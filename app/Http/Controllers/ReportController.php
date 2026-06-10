<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\DailyCheck;
use App\Models\SaleItem;
use App\Models\MilkProduction;
use App\Models\FeedPurchase;
use App\Models\WaterReading;
use App\Models\EnergyReading;
use App\Models\FuelPurchase;
use App\Models\Payslip;
use App\Models\Species;
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
     * NURSERIE / REPRODUCTION
     *
     * Suivi des naissances et sevrages (agnelage, chevrotage, lapins...) sur
     * une période, à partir des métriques born/weaned saisies au pointage.
     * Donne le taux de sevrage par lot — indicateur clé de productivité
     * numérique des reproducteurs.
     *
     * Gate : elevage.L.
     */
    public function nurseryReport(Request $request): View
    {
        if (Gate::denies('elevage.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $from = Carbon::parse($request->get('date_from', now()->startOfYear()->toDateString()))->startOfDay();
        $to   = Carbon::parse($request->get('date_to', now()->toDateString()))->endOfDay();

        $rows = DailyCheck::whereBetween('check_date', [$from, $to])
            ->whereHas('extension', fn ($q) => $q->where('qty_born', '>', 0)->orWhere('qty_weaned', '>', 0))
            ->with(['batch.species', 'batch.building', 'extension'])
            ->get()
            ->groupBy('batch_id')
            ->map(function ($checks) {
                $batch = $checks->first()->batch;
                $born   = (int) $checks->sum(fn ($c) => $c->extension->qty_born ?? 0);
                $weaned = (int) $checks->sum(fn ($c) => $c->extension->qty_weaned ?? 0);
                return [
                    'batch'        => $batch,
                    'species'      => $batch?->species?->name_fr ?? '—',
                    'icon'         => $batch?->species?->icon ?? '🐾',
                    'born'         => $born,
                    'weaned'       => $weaned,
                    'weaning_rate' => $born > 0 ? round(($weaned / $born) * 100, 1) : null,
                ];
            })
            ->filter(fn ($r) => $r['batch'] !== null)
            ->sortByDesc('born')
            ->values();

        $totalBorn   = $rows->sum('born');
        $totalWeaned = $rows->sum('weaned');
        $avgWeaningRate = $totalBorn > 0 ? round(($totalWeaned / $totalBorn) * 100, 1) : 0;

        return view('reports.nursery', compact('from', 'to', 'rows', 'totalBorn', 'totalWeaned', 'avgWeaningRate'));
    }

    /**
     * COMPTE DE RÉSULTAT CONSOLIDÉ (P&L)
     *
     * Consolide sur une période tous les flux réels déjà saisis dans l'ERP :
     * produits (ventes validées par catégorie + lait collecté valorisé) et
     * charges (achats d'animaux, aliment, santé, main d'œuvre, eau, énergie,
     * gasoil). Donne le résultat net + la marge, et une marge directe par
     * espèce (hors frais généraux). Requêtes par plage de dates (compatibles
     * SQLite/MySQL — pas de fonctions YEAR()/MONTH()).
     *
     * Gate : admin.L (donnée financière sensible).
     */
    public function profitLoss(Request $request): View
    {
        if (Gate::denies('admin.L')) {
            abort(403, 'Accès réservé.');
        }

        $from = Carbon::parse($request->get('date_from', now()->startOfYear()->toDateString()))->startOfDay();
        $to   = Carbon::parse($request->get('date_to', now()->toDateString()))->endOfDay();

        // ─── PRODUITS ───
        // Ventes réellement engagées (validées/livrées) sur la période, par catégorie.
        $salesByType = SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->whereIn('status', ['valide', 'livre'])
                ->whereBetween('sale_date', [$from, $to]))
            ->selectRaw('product_type, SUM(total) as total')
            ->groupBy('product_type')
            ->pluck('total', 'product_type');

        // Lait collecté valorisé (pas de flux de vente dédié à ce stade).
        $milkRevenue = (float) MilkProduction::whereBetween('production_date', [$from, $to])
            ->sum(DB::raw('total_liters * unit_price'));

        $revenue = [];
        foreach ($salesByType as $type => $total) {
            $revenue[$this->productTypeLabel($type)] = (float) $total;
        }
        if ($milkRevenue > 0) {
            $revenue['Lait (collecte valorisée)'] = ($revenue['Lait (collecte valorisée)'] ?? 0) + $milkRevenue;
        }
        $totalRevenue = array_sum($revenue);

        // ─── CHARGES ───
        $costAcquisition = (float) Batch::whereBetween('arrival_date', [$from, $to])->sum('total_acquisition_cost');
        $costFeed   = (float) FeedPurchase::whereBetween('purchase_date', [$from, $to])
            ->sum(DB::raw('COALESCE(total_price, quantity * unit_price)'));
        $costHealth = (float) HealthCheck::whereBetween('intervention_date', [$from, $to])->sum('cost');
        $costLabor  = (float) Payslip::whereHas('period', fn ($q) => $q->whereBetween('start_date', [$from, $to]))
            ->sum('net_salary');
        $costWater  = (float) WaterReading::whereBetween('reading_date', [$from, $to])->sum('cost');
        $costEnergy = (float) EnergyReading::whereBetween('reading_date', [$from, $to])->sum('cost');
        $costFuel   = (float) FuelPurchase::whereBetween('purchase_date', [$from, $to])->sum('total_cost');

        $costs = [
            'Achats animaux (lots)'   => $costAcquisition,
            'Aliment'                 => $costFeed,
            'Santé / prophylaxie'     => $costHealth,
            'Main d\'œuvre (paie)'    => $costLabor,
            'Eau'                     => $costWater,
            'Énergie'                 => $costEnergy,
            'Gasoil'                  => $costFuel,
        ];
        $totalCosts = array_sum($costs);
        $netResult  = $totalRevenue - $totalCosts;
        $marginPct  = $totalRevenue > 0 ? round(($netResult / $totalRevenue) * 100, 1) : 0;

        // ─── MARGE DIRECTE PAR ESPÈCE (items traçables uniquement) ───
        $speciesMargin = $this->speciesDirectMargin($from, $to);

        return view('reports.profit-loss', compact(
            'from', 'to', 'revenue', 'totalRevenue',
            'costs', 'totalCosts', 'netResult', 'marginPct', 'speciesMargin'
        ));
    }

    /**
     * Marge directe par espèce (revenus & coûts directement traçables au lot).
     * Hors frais généraux (paie, eau, énergie) non ventilables par espèce.
     */
    private function speciesDirectMargin(Carbon $from, Carbon $to): array
    {
        $rows = [];

        foreach (Species::orderBy('sort_order')->get() as $sp) {
            $batchIds = Batch::where('species_id', $sp->id)->pluck('id');
            if ($batchIds->isEmpty()) continue;

            $rev = (float) SaleItem::whereIn('batch_id', $batchIds)
                ->whereHas('sale', fn ($q) => $q->whereIn('status', ['valide', 'livre'])
                    ->whereBetween('sale_date', [$from, $to]))
                ->sum('total');
            $rev += (float) MilkProduction::whereIn('batch_id', $batchIds)
                ->whereBetween('production_date', [$from, $to])
                ->sum(DB::raw('total_liters * unit_price'));

            $cost = (float) Batch::whereIn('id', $batchIds)->whereBetween('arrival_date', [$from, $to])->sum('total_acquisition_cost');
            $cost += (float) FeedPurchase::whereIn('batch_id', $batchIds)->whereBetween('purchase_date', [$from, $to])
                ->sum(DB::raw('COALESCE(total_price, quantity * unit_price)'));
            $cost += (float) HealthCheck::whereIn('batch_id', $batchIds)->whereBetween('intervention_date', [$from, $to])->sum('cost');

            if ($rev == 0 && $cost == 0) continue;

            $rows[] = [
                'species' => $sp->name_fr,
                'icon'    => $sp->icon,
                'revenue' => $rev,
                'cost'    => $cost,
                'margin'  => $rev - $cost,
            ];
        }

        return $rows;
    }

    private function productTypeLabel(string $type): string
    {
        return match ($type) {
            'animal_vif'        => 'Animaux vifs',
            'carcasse', 'volaille_abattue' => 'Carcasses / viande',
            'volaille_vivante'  => 'Animaux vifs (volaille)',
            'oeufs'             => 'Œufs',
            'lait'              => 'Lait',
            'aliment'           => 'Aliment (revente)',
            'fumier'            => 'Fumier',
            'materiel'          => 'Matériel',
            default             => ucfirst($type),
        };
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

    /**
     * Rapport GMQ (Gain Moyen Quotidien) pour les lots ruminants.
     */
    public function gmqReport(Request $request): View
    {
        ['batchStats' => $batchStats, 'avgGmq' => $avgGmq, 'statusFilter' => $statusFilter] = $this->buildGmqStats($request);

        return view('reports.gmq', compact('batchStats', 'avgGmq', 'statusFilter'));
    }

    /**
     * Export PDF du rapport GMQ.
     */
    public function gmqReportPdf(Request $request)
    {
        ['batchStats' => $batchStats, 'avgGmq' => $avgGmq, 'statusFilter' => $statusFilter] = $this->buildGmqStats($request);

        $pdf = \Pdf::loadView('reports.pdf.gmq', compact('batchStats', 'avgGmq', 'statusFilter'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('rapport-gmq-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildGmqStats(Request $request): array
    {
        $farmId = session('current_farm_id');

        $query = \App\Models\Batch::with(['species', 'building', 'dailyChecks' => function($q) {
                $q->orderBy('check_date');
            }, 'dailyChecks.extension'])
            ->whereHas('species', function($q) {
                $q->whereIn('family', ['petit_ruminant', 'grand_ruminant', 'porcin', 'lagomorphe']);
            })
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId));

        $statusFilter = $request->input('status', 'Actif');
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $batches = $query->orderByDesc('arrival_date')->get();

        // Compute GMQ per batch
        $batchStats = $batches->map(function($batch) {
            $checks = $batch->dailyChecks->filter(fn($c) => $c->avg_weight > 0)->values();

            // Portées (porcins, lagomorphes) : taille moyenne et taux de sevrage
            $totalBorn   = $batch->dailyChecks->sum(fn($c) => $c->extension?->qty_born ?? 0);
            $totalWeaned = $batch->dailyChecks->sum(fn($c) => $c->extension?->qty_weaned ?? 0);
            $birthEvents = $batch->dailyChecks->filter(fn($c) => ($c->extension?->qty_born ?? 0) > 0)->count();
            $litterStats = [
                'total_born'      => $totalBorn,
                'total_weaned'    => $totalWeaned,
                'avg_litter_size' => $birthEvents > 0 ? round($totalBorn / $birthEvents, 1) : null,
                'weaning_rate'    => $totalBorn > 0 ? round(($totalWeaned / $totalBorn) * 100, 1) : null,
            ];

            if ($checks->count() < 2) {
                return [
                    'batch'        => $batch,
                    'gmq'          => null,
                    'gmq_series'   => [],
                    'start_weight' => $batch->avg_weight_start,
                    'last_weight'  => $checks->last()?->avg_weight,
                    'age_days'     => $batch->age,
                    ...$litterStats,
                ];
            }

            $first = $checks->first();
            $last  = $checks->last();
            $days  = max(1, \Carbon\Carbon::parse($first->check_date)->diffInDays($last->check_date));
            $gmq   = round((($last->avg_weight - $first->avg_weight) * 1000) / $days); // g/jour

            // Series for sparkline: [date => weight]
            $series = $checks->mapWithKeys(fn($c) => [
                \Carbon\Carbon::parse($c->check_date)->format('d/m') => round((float)$c->avg_weight, 3)
            ])->toArray();

            return [
                'batch'        => $batch,
                'gmq'          => $gmq,
                'gmq_series'   => $series,
                'start_weight' => $first->avg_weight,
                'last_weight'  => $last->avg_weight,
                'age_days'     => $batch->age,
                ...$litterStats,
            ];
        });

        $avgGmq = $batchStats->whereNotNull('gmq')->avg('gmq');

        return compact('batchStats', 'avgGmq', 'statusFilter');
    }

    /**
     * Rapport Pisciculture : qualité de l'eau et survie pour les lots aquacoles.
     */
    public function aquacultureReport(Request $request): View
    {
        $stats = $this->buildAquacultureStats($request);

        return view('reports.aquaculture', $stats);
    }

    /**
     * Export PDF du rapport Pisciculture.
     */
    public function aquacultureReportPdf(Request $request)
    {
        $stats = $this->buildAquacultureStats($request);

        $pdf = \Pdf::loadView('reports.pdf.aquaculture', $stats)
            ->setPaper('a4', 'portrait');

        return $pdf->download('rapport-pisciculture-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildAquacultureStats(Request $request): array
    {
        $farmId = session('current_farm_id');

        $query = \App\Models\Batch::with(['species', 'building', 'dailyChecks' => function($q) {
                $q->orderBy('check_date')->with('extension');
            }])
            ->whereHas('species', function($q) {
                $q->where('family', 'aquaculture');
            })
            ->when($farmId, fn($q) => $q->where('farm_id', $farmId));

        $statusFilter = $request->input('status', 'Actif');
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $batches = $query->orderByDesc('arrival_date')->get();

        $batchStats = $batches->map(function($batch) {
            $checks = $batch->dailyChecks->filter(fn($c) => $c->extension !== null)->values();

            $series = [
                'ph'        => [],
                'o2'        => [],
                'temp'      => [],
                'ammonia'   => [],
                'biomass'   => [],
                'survival'  => [],
            ];

            foreach ($checks as $c) {
                $date = \Carbon\Carbon::parse($c->check_date)->format('d/m');
                $ext  = $c->extension;
                if ($ext->water_ph !== null)       $series['ph'][$date]       = (float) $ext->water_ph;
                if ($ext->water_o2_ppm !== null)   $series['o2'][$date]       = (float) $ext->water_o2_ppm;
                if ($ext->water_temp !== null)     $series['temp'][$date]     = (float) $ext->water_temp;
                if ($ext->water_ammonia_ppm !== null) $series['ammonia'][$date] = (float) $ext->water_ammonia_ppm;
                if ($ext->biomass_kg !== null)     $series['biomass'][$date]   = (float) $ext->biomass_kg;
                if ($ext->survival_rate !== null)  $series['survival'][$date]  = (float) $ext->survival_rate;
            }

            $lastExt = $checks->last()?->extension;

            return [
                'batch'    => $batch,
                'series'   => $series,
                'last_ext' => $lastExt,
                'alerts'   => $lastExt?->getWaterAlerts() ?? [],
                'age_days' => $batch->age,
            ];
        });

        $totalAlerts = $batchStats->sum(fn($s) => count($s['alerts']));
        $criticalCount = $batchStats->sum(fn($s) => collect($s['alerts'])->where('level', 'critical')->count());

        return compact('batchStats', 'statusFilter', 'totalAlerts', 'criticalCount');
    }
}
