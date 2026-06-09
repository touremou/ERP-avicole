<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\DailyCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
     */
    public function monthlyExpenses(Request $request)
    {
        if (Gate::denies('admin.L')) return back()->with('error', 'Accès réservé.');

        $currentYear = (int) $request->get('year', date('Y'));
        $statusFilter = $request->get('status', 'all');
        $monthFilter = $request->get('month', 'all');
        $bagWeight = (float) setting('general.feed_bag_weight', 50);

        $query = Batch::with(['building', 'feedPurchases']);

        if ($statusFilter === 'actif') {
            $query->where('status', 'Actif');
        } elseif ($statusFilter === 'termine' || $statusFilter === 'clos') {
            $query->whereIn('status', ['Terminé', 'Clôturé']);
        }

        $batches = $query
        ->live()
        ->get();
        $batchIds = $batches->pluck('id');

        // Données santé par mois
        $healthData = HealthCheck::whereIn('batch_id', $batchIds)
            ->whereYear('intervention_date', $currentYear)
            ->when($monthFilter !== 'all', fn($q) => $q->whereMonth('intervention_date', $monthFilter))
            ->select('batch_id', DB::raw('SUM(cost) as total_health'), DB::raw('MONTH(intervention_date) as month'))
            ->groupBy('month', 'batch_id')->get();

        // Consommation aliment par mois
        $feedConsump = DailyCheck::whereIn('batch_id', $batchIds)
            ->whereYear('check_date', $currentYear)
            ->when($monthFilter !== 'all', fn($q) => $q->whereMonth('check_date', $monthFilter))
            ->select('batch_id', DB::raw('SUM(feed_consumed) as qty'), DB::raw('MONTH(check_date) as month'))
            ->groupBy('month', 'batch_id')->get();

        $monthlyData = [];

        foreach ($batches as $batch) {
            // CMUP aliment
            $totalFeedCost = 0;
            $totalFeedKg = 0;
            foreach ($batch->feedPurchases as $purchase) {
                $totalFeedCost += (float) $purchase->quantity * (float) $purchase->unit_price;
                $kg = strtolower($purchase->unit) === 'sac' ? (float) $purchase->quantity * $bagWeight : (float) $purchase->quantity;
                $totalFeedKg += $kg;
            }
            $avgPricePerKg = $totalFeedKg > 0 ? ($totalFeedCost / $totalFeedKg) : 0;

            // ═══ DÉTERMINER LES MOIS D'ACTIVITÉ DU LOT ═══
            $arrivalDate = $batch->arrival_date ? Carbon::parse($batch->arrival_date) : null;
            $closingDate = $batch->closing_date ? Carbon::parse($batch->closing_date) : now();

            if (! $arrivalDate) continue;

            // Mois où le lot était actif dans l'année courante
            $startMonth = $arrivalDate->year < $currentYear ? 1 : $arrivalDate->month;
            $endMonth = $closingDate->year > $currentYear ? 12 : $closingDate->month;

            if ($arrivalDate->year > $currentYear) continue; // Lot pas encore arrivé cette année
            if ($closingDate->year < $currentYear) continue; // Lot terminé avant cette année

            // Filtrer par mois si demandé
            if ($monthFilter !== 'all') {
                $m = (int) $monthFilter;
                if ($m < $startMonth || $m > $endMonth) continue;
                $startMonth = $m;
                $endMonth = $m;
            }

            // Peupler chaque mois d'activité
            for ($m = $startMonth; $m <= $endMonth; $m++) {
                if (! isset($monthlyData[$m][$batch->id])) {
                    $monthlyData[$m][$batch->id] = [
                        'batch'     => $batch,
                        'health'    => 0,
                        'feed_qty'  => 0,
                        'feed_cost' => 0,
                    ];
                }
            }

            // Ajouter les données santé
            foreach ($healthData->where('batch_id', $batch->id) as $h) {
                if (isset($monthlyData[$h->month][$batch->id])) {
                    $monthlyData[$h->month][$batch->id]['health'] = $h->total_health;
                }
            }

            // Ajouter les données aliment
            foreach ($feedConsump->where('batch_id', $batch->id) as $f) {
                if (isset($monthlyData[$f->month][$batch->id])) {
                    $monthlyData[$f->month][$batch->id]['feed_qty'] = $f->qty;
                    $monthlyData[$f->month][$batch->id]['feed_cost'] = $f->qty * $avgPricePerKg;
                }
            }
        }

        // Trier les mois
        ksort($monthlyData);

        $months = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
                   7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];

        return view('reports.monthly', compact('monthlyData', 'months', 'currentYear', 'statusFilter', 'monthFilter'));
    }
}
