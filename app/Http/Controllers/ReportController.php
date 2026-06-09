<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\DailyCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * HUB DES RAPPORTS
     * Gate : elevage.L — accessible à tout utilisateur avec accès élevage
     */
    public function index()
    {
        if (Gate::denies('elevage.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        return view('reports.index');
    }

    /**
     * Rapport Financier : Coût de Santé (Prophylaxie)
     * Gate : elevage.L — données de santé par lot
     */
    public function healthFinancialReport(Request $request)
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $period = $request->get('period', 'all');
        $statusFilter = $request->get('status', 'all');

        $healthCheckQuery = function ($query) use ($period) {
            if ($period === 'month') {
                $query->whereMonth('intervention_date', now()->month)
                      ->whereYear('intervention_date', now()->year);
            } elseif ($period === 'year') {
                $query->whereYear('intervention_date', now()->year);
            }
        };

        $query = Batch::with(['building', 'healthChecks' => $healthCheckQuery]);

        if ($statusFilter === 'actif') {
            $query->where('status', 'Actif');
        } elseif ($statusFilter === 'clos') {
            $query->where('status', 'Terminé');
        }

        $batches = $query->get();

        $typeBreakdown = [];
        $totalGlobalCost = 0;

        foreach ($batches as $batch) {
            foreach ($batch->healthChecks as $hc) {
                $typeBreakdown[$hc->type] = ($typeBreakdown[$hc->type] ?? 0) + $hc->cost;
                $totalGlobalCost += $hc->cost;
            }
        }

        $totalBirdsInitial = $batches->sum('initial_quantity');
        $averageCostPerHead = $totalBirdsInitial > 0 ? $totalGlobalCost / $totalBirdsInitial : 0;

        $bestBatch = $batches->filter(fn($b) => $b->initial_quantity > 0)
            ->sortBy(fn($b) => $b->healthChecks->sum('cost') / $b->initial_quantity)
            ->first();

        $bestBatchCost = $bestBatch ? ($bestBatch->healthChecks->sum('cost') / $bestBatch->initial_quantity) : 0;

        return view('reports.health_finance', compact(
            'batches', 'totalGlobalCost', 'averageCostPerHead',
            'bestBatch', 'bestBatchCost', 'typeBreakdown', 'period', 'statusFilter'
        ));
    }

    /**
     * ANALYSE DE LA PERFORMANCE TECHNIQUE (KPIs)
     * Gate : elevage.L — données techniques par lot
     */
    public function technicalPerformance()
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $activeBatches = Batch::with('building')
            ->where('status', 'Actif')
            ->withSum('dailyChecks as total_mortality', 'mortality')
            ->withSum('dailyChecks as total_feed_consumed', 'feed_consumed')
            ->live()
            ->get();

        $latestChecks = DailyCheck::select('batch_id', 'avg_weight', 'check_date')
            ->whereIn('batch_id', $activeBatches->pluck('id'))
            ->whereIn('check_date', function ($query) {
                $query->selectRaw('MAX(check_date)')
                      ->from('daily_checks')
                      ->groupBy('batch_id');
            })->get()->keyBy('batch_id');

        // ✅ setting() pour les seuils de mortalité
        $seuilCritique = (float) setting('elevage.mortality_alert', 5);
        $seuilAlerte = $seuilCritique * 0.6; // 60% du seuil critique = alerte

        $stats = $activeBatches->map(function ($batch) use ($latestChecks, $seuilCritique, $seuilAlerte) {
            $initial = $batch->initial_quantity;
            $totalMortalite = $batch->total_mortality ?? 0;
            $current = $initial - $totalMortalite;

            $tauxMortalite = $initial > 0 ? ($totalMortalite / $initial) * 100 : 0;
            $age = Carbon::parse($batch->arrival_date)->diffInDays(now()) + 1;

            $lastCheck = $latestChecks->get($batch->id);
            $avgWeightGrams = $lastCheck ? ($lastCheck->avg_weight * 1000) : 0;

            // FCR corrigé
            $totalFeedKg = $batch->total_feed_consumed ?? 0;
            $biomassVivantsKg = ($current * ($avgWeightGrams / 1000));
            $poidsMoyenMort = $avgWeightGrams * 0.5;
            $biomassMortsKg = ($totalMortalite * ($poidsMoyenMort / 1000));
            $totalBiomassKg = $biomassVivantsKg + $biomassMortsKg;
            $fcr = ($totalBiomassKg > 0) ? ($totalFeedKg / $totalBiomassKg) : 0;

            return [
                'id'              => $batch->id,
                'code'            => $batch->code,
                'type'            => $batch->type,
                'building'        => $batch->building->name ?? 'N/A',
                'fcr'             => round($fcr, 2),
                'age'             => $age,
                'initial'         => $initial,
                'current'         => $current,
                'mortality_count' => $totalMortalite,
                'mortality_rate'  => round($tauxMortalite, 2),
                'avg_weight'      => $avgWeightGrams,
                'daily_gain'      => $age > 0 ? round($avgWeightGrams / $age, 1) : 0,
                'status'          => $tauxMortalite > $seuilCritique ? 'Critique' : ($tauxMortalite > $seuilAlerte ? 'Alerte' : 'Normal'),
            ];
        });

        return view('reports.technical', compact('stats'));
    }

    /**
     * ANALYSE FINANCIÈRE GLOBALE (Coût de production)
     * Gate : admin.L — données financières sensibles
     *
     * Filtres disponibles :
     *  - year       : année (défaut = année courante)
     *  - status     : all / actif / termine
     *  - month      : 1-12 / all
     *  - type       : chair / ponte / poussiniere / reproducteur / all
     *  - date_from  : plage libre (YYYY-MM-DD), prioritaire sur year+month
     *  - date_to    : plage libre (YYYY-MM-DD)
     */
    public function monthlyExpenses(Request $request)
    {
        if (Gate::denies('admin.L')) return back()->with('error', 'Accès réservé.');

        $currentYear  = (int) $request->get('year', date('Y'));
        $statusFilter = $request->get('status', 'all');
        $monthFilter  = $request->get('month', 'all');
        $typeFilter   = $request->get('type', 'all');
        $dateFrom     = $request->get('date_from');
        $dateTo       = $request->get('date_to');
        $bagWeight    = (float) setting('general.feed_bag_weight', 50);

        // Plage libre : si date_from/date_to fournis, ils priment sur year/month
        $useDateRange = $dateFrom && $dateTo;
        $rangeStart   = $useDateRange ? Carbon::parse($dateFrom)->startOfDay() : null;
        $rangeEnd     = $useDateRange ? Carbon::parse($dateTo)->endOfDay()     : null;

        // ─── LISTE DES ANNÉES DISPONIBLES POUR LE SÉLECTEUR ───
        $availableYears = Batch::selectRaw('YEAR(arrival_date) as yr')
            ->whereNotNull('arrival_date')
            ->distinct()->orderByDesc('yr')
            ->pluck('yr')->filter()->toArray();
        if (empty($availableYears)) {
            $availableYears = [date('Y')];
        }

        $query = Batch::with(['building', 'feedPurchases'])->live();

        if ($statusFilter === 'actif') {
            $query->where('status', 'Actif');
        } elseif (in_array($statusFilter, ['termine', 'clos'])) {
            $query->whereIn('status', ['Terminé', 'Clôturé']);
        }

        if ($typeFilter !== 'all') {
            $query->where('type', $typeFilter);
        }

        $batches   = $query->get();
        $batchIds  = $batches->pluck('id');

        // ─── REQUÊTES SANTÉ & ALIMENT AVEC PLAGE DYNAMIQUE ───
        $healthQuery = HealthCheck::whereIn('batch_id', $batchIds);
        $feedQuery   = DailyCheck::whereIn('batch_id', $batchIds);

        if ($useDateRange) {
            $healthQuery->whereBetween('intervention_date', [$rangeStart, $rangeEnd]);
            $feedQuery->whereBetween('check_date', [$rangeStart, $rangeEnd]);
        } else {
            $healthQuery->whereYear('intervention_date', $currentYear)
                ->when($monthFilter !== 'all', fn($q) => $q->whereMonth('intervention_date', $monthFilter));
            $feedQuery->whereYear('check_date', $currentYear)
                ->when($monthFilter !== 'all', fn($q) => $q->whereMonth('check_date', $monthFilter));
        }

        $healthData = $healthQuery
            ->select('batch_id', DB::raw('SUM(cost) as total_health'), DB::raw('MONTH(intervention_date) as month'))
            ->groupBy('month', 'batch_id')->get();

        $feedConsump = $feedQuery
            ->select('batch_id', DB::raw('SUM(feed_consumed) as qty'), DB::raw('MONTH(check_date) as month'))
            ->groupBy('month', 'batch_id')->get();

        $monthlyData = [];

        foreach ($batches as $batch) {
            // CMUP aliment depuis les achats
            $totalFeedCostAll = 0;
            $totalFeedKgAll   = 0;
            foreach ($batch->feedPurchases as $purchase) {
                $totalFeedCostAll += (float) $purchase->quantity * (float) $purchase->unit_price;
                $kg = strtolower($purchase->unit ?? '') === 'sac'
                    ? (float) $purchase->quantity * $bagWeight
                    : (float) $purchase->quantity;
                $totalFeedKgAll += $kg;
            }
            $avgPricePerKg = $totalFeedKgAll > 0 ? ($totalFeedCostAll / $totalFeedKgAll) : 0;

            // Coût acquisition
            $acquisitionCost = (float) ($batch->total_acquisition_cost
                ?: ($batch->buy_price_per_unit * $batch->initial_quantity));

            $arrivalDate = $batch->arrival_date ? Carbon::parse($batch->arrival_date) : null;
            $closingDate = $batch->closing_date ? Carbon::parse($batch->closing_date) : now();

            if (! $arrivalDate) continue;

            if ($useDateRange) {
                // En plage libre : on affiche dans un "mois" synthétique = 0
                $startMonth = 0;
                $endMonth   = 0;
                // Vérifier chevauchement avec la plage
                if ($arrivalDate->gt($rangeEnd) || $closingDate->lt($rangeStart)) continue;
            } else {
                $startMonth = $arrivalDate->year < $currentYear ? 1 : $arrivalDate->month;
                $endMonth   = $closingDate->year > $currentYear ? 12 : $closingDate->month;
                if ($arrivalDate->year > $currentYear) continue;
                if ($closingDate->year < $currentYear) continue;
                if ($monthFilter !== 'all') {
                    $m = (int) $monthFilter;
                    if ($m < $startMonth || $m > $endMonth) continue;
                    $startMonth = $m;
                    $endMonth   = $m;
                }
            }

            for ($m = $startMonth; $m <= $endMonth; $m++) {
                if (! isset($monthlyData[$m][$batch->id])) {
                    $monthlyData[$m][$batch->id] = [
                        'batch'            => $batch,
                        'health'           => 0,
                        'feed_qty'         => 0,
                        'feed_cost'        => 0,
                        'acquisition_cost' => $acquisitionCost,
                        'avg_price_per_kg' => $avgPricePerKg,
                    ];
                }
            }

            foreach ($healthData->where('batch_id', $batch->id) as $h) {
                $key = $useDateRange ? 0 : $h->month;
                if (isset($monthlyData[$key][$batch->id])) {
                    $monthlyData[$key][$batch->id]['health'] += $h->total_health;
                }
            }

            foreach ($feedConsump->where('batch_id', $batch->id) as $f) {
                $key = $useDateRange ? 0 : $f->month;
                if (isset($monthlyData[$key][$batch->id])) {
                    $monthlyData[$key][$batch->id]['feed_qty']  += $f->qty;
                    $monthlyData[$key][$batch->id]['feed_cost'] += $f->qty * $avgPricePerKg;
                }
            }
        }

        ksort($monthlyData);

        // ─── TOTAUX GLOBAUX (récapitulatif en haut du rapport) ───
        $globalFeedCost  = 0;
        $globalHealthCost = 0;
        $globalAcqCost   = 0;
        $globalFeedQty   = 0;
        $globalHeads     = 0;

        foreach ($monthlyData as $mData) {
            foreach ($mData as $d) {
                $globalFeedCost   += $d['feed_cost'];
                $globalHealthCost += $d['health'];
                $globalFeedQty    += $d['feed_qty'];
            }
        }
        // Acquisition unique par batch (pas multiplié par mois)
        $seenBatches = [];
        foreach ($monthlyData as $mData) {
            foreach ($mData as $bId => $d) {
                if (! isset($seenBatches[$bId])) {
                    $seenBatches[$bId] = true;
                    $globalAcqCost += $d['acquisition_cost'];
                    $globalHeads   += $d['batch']->initial_quantity;
                }
            }
        }
        $globalTotalCost  = $globalFeedCost + $globalHealthCost + $globalAcqCost;
        $globalCostPerHead = $globalHeads > 0 ? $globalTotalCost / $globalHeads : 0;

        $globalStats = [
            'feed_cost'      => $globalFeedCost,
            'health_cost'    => $globalHealthCost,
            'acq_cost'       => $globalAcqCost,
            'total_cost'     => $globalTotalCost,
            'feed_qty'       => $globalFeedQty,
            'heads'          => $globalHeads,
            'cost_per_head'  => $globalCostPerHead,
            'feed_pct'       => $globalTotalCost > 0 ? round($globalFeedCost / $globalTotalCost * 100, 1) : 0,
            'health_pct'     => $globalTotalCost > 0 ? round($globalHealthCost / $globalTotalCost * 100, 1) : 0,
            'acq_pct'        => $globalTotalCost > 0 ? round($globalAcqCost / $globalTotalCost * 100, 1) : 0,
        ];

        $months = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
                   7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];

        return view('reports.monthly', compact(
            'monthlyData', 'months', 'currentYear', 'statusFilter', 'monthFilter',
            'typeFilter', 'availableYears', 'globalStats',
            'dateFrom', 'dateTo', 'useDateRange'
        ));
    }
}
