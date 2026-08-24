<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\HealthCheck;
use App\Models\HealthIncident;
use App\Models\DailyCheck;
use App\Models\SaleItem;
use App\Models\MilkProduction;
use App\Models\FeedPurchase;
use App\Models\WaterReading;
use App\Models\EnergyReading;
use App\Models\Payslip;
use App\Models\Species;
use App\Models\Expense;
use App\Services\Accounting\PeriodRevenue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Carbon\Carbon;

class ReportController extends Controller
{
    /** Types d'actes du registre sanitaire, dans l'ordre d'affichage. */
    private const HEALTH_CHECK_TYPES = ['Vaccin', 'Traitement', 'Vitamine', 'Désinfection'];

    /** Poste des coûts d'incident, qui ne vient pas du registre des actes. */
    private const TYPE_INCIDENT = 'Incident sanitaire';

    /**
     * HUB DES RAPPORTS
     *
     * Gate any-of : le centre de rapports est une entrée transverse (menu de
     * premier niveau), accessible aux profils élevage (rapports techniques),
     * finance (P&L, flux) et admin — chaque tuile reste ensuite filtrée par
     * son propre droit.
     */
    public function index()
    {
        // Le centre de rapports est TRANSVERSE : il doit s'ouvrir à qui a au
        // moins un rapport à y lire. Il ignorait les cultures — un technicien
        // végétal y atterrissait sur une page qui ne le concernait pas, ou s'en
        // voyait refuser l'accès alors que des rapports existaient pour lui.
        $anyOf = ['elevage.L', 'cultures.L', 'depenses.L', 'admin.L'];

        foreach ($anyOf as $gate) {
            if (Gate::allows($gate)) {
                return view('reports.index');
            }
        }

        return redirect()->route('dashboard')->with('error', 'Accès restreint.');
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

        return view('reports.nursery', $this->buildNurseryStats($request));
    }

    /**
     * Export PDF du rapport Nurserie/Reproduction (filtres en cours appliqués).
     */
    public function nurseryReportPdf(Request $request)
    {
        if (Gate::denies('elevage.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $stats = $this->buildNurseryStats($request);

        $pdf = \Pdf::loadView('reports.pdf.nursery', $stats)->setPaper('a4', 'portrait');

        return $pdf->download('rapport-nurserie-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildNurseryStats(Request $request): array
    {
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

        return compact('from', 'to', 'rows', 'totalBorn', 'totalWeaned', 'avgWeaningRate');
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

        return view('reports.profit-loss', $this->buildProfitLossStats($request));
    }

    /**
     * Export PDF du Compte de Résultat (filtres de date en cours appliqués).
     */
    public function profitLossPdf(Request $request)
    {
        if (Gate::denies('admin.L')) {
            abort(403, 'Accès réservé.');
        }

        $stats = $this->buildProfitLossStats($request);

        $pdf = \Pdf::loadView('reports.pdf.profit-loss', $stats)->setPaper('a4', 'portrait');

        return $pdf->download('compte-resultat-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildProfitLossStats(Request $request): array
    {
        $from = Carbon::parse($request->get('date_from', now()->startOfYear()->toDateString()))->startOfDay();
        $to   = Carbon::parse($request->get('date_to', now()->toDateString()))->endOfDay();

        // ─── PRODUITS ───
        // Ventes réellement engagées (validées/livrées) sur la période, par
        // catégorie — TELLES QU'ELLES ÉTAIENT À LA CLÔTURE de la période.
        //
        // Un retour de marchandise décrémente la ligne de vente d'origine, et le
        // rapport sélectionne les ventes par leur date de vente : un retour de
        // septembre réécrivait donc le chiffre de JUILLET. La reconstitution vit
        // dans PeriodRevenue, appelée aussi par la rentabilité par espèce plus
        // bas — une règle recopiée est une règle qui diverge.
        $salesByType = collect(PeriodRevenue::byProductType($from, $to));

        /*
         * Lait collecté et valorisé : un STOCK, pas une recette.
         *
         * Il était ajouté ICI, aux ventes — or la collecte alimente l'article
         * « Lait » du magasin et `lait` est un type de vente adossé au stock :
         * les litres traits puis vendus étaient comptés DEUX FOIS.
         *
         * Le chiffre reste affiché, hors du compte des recettes : une traite non
         * encore vendue est un stock réel (cf. PeriodRevenue::milkCollectedValued).
         */
        $milkCollected = PeriodRevenue::milkCollectedValued($from, $to);

        /*
         * TVA collectée : encaissée pour l'État, donc hors recettes (#310), mais
         * elle ne doit pas disparaître de la vue pour autant. Elle est affichée
         * sous le total, nommée pour ce qu'elle est — et NON comme un montant dû,
         * la TVA déductible sur achats n'étant enregistrée nulle part.
         */
        $taxCollected = PeriodRevenue::taxCollected($from, $to);

        $revenue = [];
        foreach ($salesByType as $type => $total) {
            $revenue[$this->productTypeLabel($type)] = (float) $total;
        }

        // Production végétale : revenus des cycles CLÔTURÉS sur la période
        // (reconnaissance à la clôture, cohérente avec l'imputation de leurs
        // coûts ci-dessous — évite tout chevauchement de période).
        // Cycles RÉELLEMENT clôturés : la date de clôture ne suffit pas comme
        // critère. Un cycle rouvert la conservait et restait donc compté ici,
        // tout en continuant d'accumuler récoltes et intrants — une période
        // arrêtée qui bouge encore. La réouverture efface désormais cette date
        // (CropCycleController::reopen) ; ce filtre de statut est la seconde
        // barrière, pour que le rapport reste juste même si un autre chemin
        // venait un jour à rouvrir un cycle sans l'effacer.
        $closedCyclesQuery = CropCycle::archived()->whereBetween('closing_date', [$from, $to]);
        $closedCycleIds = (clone $closedCyclesQuery)->pluck('id');
        $cropRevenue = (float) (clone $closedCyclesQuery)->sum('total_revenue');
        if ($cropRevenue > 0) {
            $revenue['Production végétale'] = ($revenue['Production végétale'] ?? 0) + $cropRevenue;
        }

        $totalRevenue = array_sum($revenue);

        // ─── CHARGES ───
        // Déclaration UNIQUE, partagée avec le tableau de bord : les deux écrans
        // répondaient à « combien ai-je gagné ce mois-ci » sans compter les mêmes
        // charges — celui-ci les comptait toutes, l'autre oubliait la PAIE et
        // l'ACHAT DES ANIMAUX, soit les deux plus gros postes d'un élevage.
        // Les conventions (aliment au consommé, groupes exclus de l'énergie,
        // carburant en poste dédié) vivent désormais dans PeriodCharges.
        $costs = \App\Services\Accounting\PeriodCharges::between($from, $to);

        // Coûts des cycles végétaux clôturés sur la période : forfaits
        // (acquisition + additionnels) + intrants itémisés. Cohérent avec la
        // marge nette du cycle (cf. CropCycle::getNetMarginAttribute).
        /*
         * Même correction qu'à la marge par culture : on impute le coût des
         * marchandises VENDUES, pas le coût engagé. La somme précédente valait
         * exactement `CropCycle::total_cost` et ignorait donc les récoltes
         * conservées, dont le coût suit la matière en inventaire.
         *
         * On passe par la déclaration du modèle plutôt que de la recopier : une
         * seconde formule divergerait au premier ajustement.
         */
        $cropCost = (float) (clone $closedCyclesQuery)
            ->with('inputs:id,crop_cycle_id,total_cost')
            ->get()
            ->sum(fn (CropCycle $c) => $c->costOfGoodsSold());
        if ($cropCost > 0) {
            $costs['Production végétale (cultures)'] = $cropCost;
        }

        $totalCosts = array_sum($costs);
        $netResult  = $totalRevenue - $totalCosts;
        $marginPct  = $totalRevenue > 0 ? round(($netResult / $totalRevenue) * 100, 1) : 0;

        // ─── MARGE DIRECTE PAR ESPÈCE (items traçables uniquement) ───
        $speciesMargin = $this->speciesDirectMargin($from, $to);

        // ─── MARGE DIRECTE PAR CULTURE (cycles clôturés sur la période) ───
        $cropMargin = $this->cropDirectMargin($from, $to);

        // ─── REGROUPEMENT SYSCOHADA (OHADA) ───
        // Vue « par nature » : chaque produit/charge rattaché à un compte de
        // classe 7/6, regroupé par classe à 2 chiffres. Rend le P&L présentable
        // à un comptable sans introduire d'écritures en partie double.
        $mapper = new \App\Services\Accounting\SyscohadaMapper();
        $syscohadaProduits = $mapper->group($revenue, 'produit');
        $syscohadaCharges  = $mapper->group($costs, 'charge');

        return compact(
            'from', 'to', 'revenue', 'totalRevenue',
            'costs', 'totalCosts', 'netResult', 'marginPct', 'speciesMargin', 'cropMargin',
            'syscohadaProduits', 'syscohadaCharges', 'milkCollected', 'taxCollected'
        );
    }

    /**
     * Coût de l'aliment CONSOMMÉ sur la période (COGS aliment) — principe de
     * rattachement des charges. Chaque consommation de pointage est valorisée au
     * coût figé à la saisie (feed_unit_cost, cohérent avec la fiche lot) ; à
     * défaut (données antérieures au snapshot), au CMP courant de l'article. Ne
     * dépend donc PAS des achats : l'aliment produit en interne est bien compté.
     */
    private function feedConsumedCost(Carbon $from, Carbon $to): float
    {
        // Carte de repli : CMP courant par nom d'article / type d'aliment.
        $cmpByName = [];
        foreach (\App\Models\Stock::where('category', \App\Models\Stock::CAT_CONSO)->get() as $s) {
            $cmp = (float) ($s->last_unit_price ?? $s->unit_price ?? 0);
            if ($cmp <= 0) continue;
            if ($s->item_name) $cmpByName[trim($s->item_name)] = $cmp;
            if ($s->feed_type) $cmpByName[trim($s->feed_type)] = $cmp;
        }

        $total = 0.0;
        \App\Models\DailyCheck::whereBetween('check_date', [$from, $to])
            ->where('feed_consumed', '>', 0)
            ->get(['feed_consumed', 'feed_unit_cost', 'feed_type'])
            ->each(function ($f) use (&$total, $cmpByName) {
                $snapshot = (float) ($f->feed_unit_cost ?? 0);
                $unitCost = $snapshot > 0
                    ? $snapshot
                    : (float) ($cmpByName[trim((string) $f->feed_type)] ?? 0);
                $total += (float) $f->feed_consumed * $unitCost;
            });

        return round($total, 2);
    }

    /**
     * Marge directe par culture : agrège les cycles végétaux CLÔTURÉS sur la
     * période par nom de culture (revenus vs coûts forfaitaires + intrants).
     *
     * @return array<int, array{crop:string, revenue:float, cost:float, margin:float}>
     */
    private function cropDirectMargin(Carbon $from, Carbon $to): array
    {
        $cycles = CropCycle::whereBetween('closing_date', [$from, $to])
            ->with('inputs:id,crop_cycle_id,total_cost')
            ->get();

        $rows = [];
        foreach ($cycles as $cycle) {
            $crop = $cycle->crop_name;
            $revenue = (float) $cycle->total_revenue;

            /*
             * COÛT DES MARCHANDISES VENDUES, pas coût engagé.
             *
             * Cette ligne recomposait « acquisition + forfaits + intrants » —
             * soit exactement `CropCycle::total_cost`, le coût ENGAGÉ. Or le
             * revenu en face ne compte que les récoltes VENDUES.
             *
             * Un cycle dont une partie de la récolte sèche ou attend un meilleur
             * prix se voyait donc imputer le coût de ce qui est encore en
             * inventaire, sans la recette correspondante : marge catastrophique
             * le mois de la récolte, puis profit sans coût le mois de la vente.
             *
             * Le modèle porte déjà la bonne déclaration — `costOfGoodsSold()`
             * retire la valorisation des récoltes conservées, et son commentaire
             * décrit précisément ce piège. Ce rapport ne l'avait jamais suivie.
             */
            $cost = $cycle->costOfGoodsSold();

            if (! isset($rows[$crop])) {
                $rows[$crop] = ['crop' => $crop, 'revenue' => 0.0, 'cost' => 0.0, 'margin' => 0.0];
            }
            $rows[$crop]['revenue'] += $revenue;
            $rows[$crop]['cost']    += $cost;
            $rows[$crop]['margin']  += $revenue - $cost;
        }

        return array_values($rows);
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

            // Même reconstitution que le compte de résultat : un retour postérieur
            // à la période ne doit pas rétrécir la rentabilité d'une espèce sur un
            // mois déjà arrêté.
            // La collecte de lait n'est PAS ajoutée ici : elle alimente le stock,
            // et sa vente est déjà comptée par PeriodRevenue (cf.
            // PeriodRevenue::milkCollectedValued). L'ajouter comptait les mêmes
            // litres deux fois, et gonflait la rentabilité de l'espèce laitière.
            $rev = PeriodRevenue::forBatches($batchIds, $from, $to);

            $cost = (float) Batch::whereIn('id', $batchIds)->whereBetween('arrival_date', [$from, $to])->sum('total_acquisition_cost');
            $cost += (float) FeedPurchase::whereIn('batch_id', $batchIds)->whereBetween('purchase_date', [$from, $to])
                ->sum(DB::raw('COALESCE(total_price, quantity * unit_price)'));
            $cost += (float) HealthCheck::whereIn('batch_id', $batchIds)->whereBetween('intervention_date', [$from, $to])->sum('cost');
            $cost += (float) Expense::validated()->whereIn('batch_id', $batchIds)
                ->whereBetween('expense_date', [$from, $to])->sum('amount');

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
        // Déjà un libellé, pas un code : les retours antérieurs à l'instantané
        // de catégorie (migration du 19/08/2026) arrivent sous leur propre nom.
        if ($type === PeriodRevenue::LIBELLE_NON_VENTILE) {
            return $type;
        }

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

        return view('reports.health_finance', $this->buildHealthFinanceStats($request));
    }

    /**
     * Rapport sanitaire : analyse des incidents par maladie, gravité, bâtiment
     * et par mois (saisonnalité) sur une période. Aide à repérer les pathologies
     * récurrentes et les foyers (bâtiments à risque).
     */
    public function healthIncidentsReport(Request $request): View
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->subDays(90)->startOfDay();
        $to   = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        $incidents = HealthIncident::with(['building', 'batch'])
            ->whereBetween('incident_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $byDisease = $incidents
            ->groupBy(fn ($i) => $i->suspected_disease ?: 'Non diagnostiqué')
            ->map(fn ($g) => [
                'count'     => $g->count(),
                'mortality' => (int) $g->sum('mortality_count'),
                'cost'      => (float) $g->sum('treatment_cost'),
            ])
            ->sortByDesc('count');

        $bySeverity = $incidents->groupBy('severity')->map->count();
        $byBuilding = $incidents->groupBy(fn ($i) => $i->building->name ?? '—')->map->count()->sortDesc();
        $byMonth    = $incidents->groupBy(fn ($i) => optional($i->incident_date)->format('Y-m'))->map->count()->sortKeys();

        $resolvedTimes = $incidents->where('status', 'resolu')->filter(fn ($i) => $i->resolved_at && $i->incident_date)
            ->map(fn ($i) => $i->incident_date->startOfDay()->diffInDays($i->resolved_at->startOfDay()));

        $summary = [
            'total'               => $incidents->count(),
            'open'                => $incidents->where('status', '!=', 'resolu')->count(),
            'mortality'           => (int) $incidents->sum('mortality_count'),
            'cost'                => (float) $incidents->sum('treatment_cost'),
            'avg_resolution_days' => $resolvedTimes->isNotEmpty() ? round($resolvedTimes->avg(), 1) : null,
        ];

        return view('reports.health-incidents', compact('from', 'to', 'incidents', 'byDisease', 'bySeverity', 'byBuilding', 'byMonth', 'summary'));
    }

    /**
     * Export PDF du rapport Santé & Pharmacie (filtres en cours appliqués).
     */
    public function healthFinancialReportPdf(Request $request)
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $stats = $this->buildHealthFinanceStats($request);

        $pdf = \Pdf::loadView('reports.pdf.health-finance', $stats)->setPaper('a4', 'portrait');

        return $pdf->download('rapport-sante-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildHealthFinanceStats(Request $request): array
    {
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

        /*
         * LES ÉPIDÉMIES MANQUAIENT AU RAPPORT QUI CHIFFRE LA SANTÉ.
         *
         * Ce rapport ne sommait que les actes du registre. Le coût de traitement
         * d'un INCIDENT sanitaire — l'événement le plus cher, précisément — n'y
         * entrait pas, alors que la marge du lot et le compte de résultat le
         * comptent. Le « coût sanitaire par tête », titre de l'écran, était donc
         * sous-estimé de ce qui coûte le plus.
         *
         * Même borne de période que les actes, transposée à la date d'incident.
         */
        $healthIncidentQuery = function ($query) use ($period) {
            if ($period === 'month') {
                $query->whereMonth('incident_date', now()->month)
                      ->whereYear('incident_date', now()->year);
            } elseif ($period === 'year') {
                $query->whereYear('incident_date', now()->year);
            }
        };

        $query = Batch::with([
            'building',
            'healthChecks'    => $healthCheckQuery,
            'healthIncidents' => $healthIncidentQuery,
        ])->live();

        if ($statusFilter === 'actif') {
            $query->active();
        } elseif ($statusFilter === 'clos') {
            $query->where('status', 'Terminé');
        }

        $batches = $query->get();

        $typeBreakdown = [];
        $totalGlobalCost = 0;

        foreach ($batches as $batch) {
            $coutLot = 0.0;

            foreach ($batch->healthChecks as $hc) {
                $typeBreakdown[$hc->type] = ($typeBreakdown[$hc->type] ?? 0) + (float) $hc->cost;
                $coutLot += (float) $hc->cost;
            }

            $coutIncidents = (float) $batch->healthIncidents->sum('treatment_cost');

            if ($coutIncidents > 0) {
                $typeBreakdown[self::TYPE_INCIDENT] = ($typeBreakdown[self::TYPE_INCIDENT] ?? 0) + $coutIncidents;
                $coutLot += $coutIncidents;
            }

            // Le coût du lot est calculé ICI, une fois. Le gabarit le recomposait
            // à sa façon (actes seuls) : trois endroits chiffraient la santé d'un
            // lot, et le tableau ne disait pas la même chose que son propre total.
            $batch->setAttribute('sanitary_cost', round($coutLot, 2));

            $totalGlobalCost += $coutLot;
        }

        $totalBirdsInitial = $batches->sum('initial_quantity');
        $averageCostPerHead = $totalBirdsInitial > 0 ? $totalGlobalCost / $totalBirdsInitial : 0;

        $bestBatch = $batches->filter(fn ($b) => $b->initial_quantity > 0)
            ->sortBy(fn ($b) => $b->sanitary_cost / $b->initial_quantity)
            ->first();

        $bestBatchCost = $bestBatch ? ($bestBatch->sanitary_cost / $bestBatch->initial_quantity) : 0;

        // Postes affichés : les types du référentiel PLUS la ligne des incidents,
        // servis par le contrôleur. Le gabarit en tenait la liste en dur et
        // n'aurait donc jamais montré un poste nouveau.
        $costTypes = array_merge(self::HEALTH_CHECK_TYPES, [self::TYPE_INCIDENT]);

        return compact(
            'batches', 'totalGlobalCost', 'averageCostPerHead',
            'bestBatch', 'bestBatchCost', 'typeBreakdown', 'costTypes', 'period', 'statusFilter'
        );
    }

    /**
     * ANALYSE DE LA PERFORMANCE TECHNIQUE (KPIs)
     * Gate : elevage.L — données techniques par lot
     */
    public function technicalPerformance()
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        return view('reports.technical', $this->buildTechnicalStats());
    }

    /**
     * Export PDF de la performance technique (lots actifs).
     */
    public function technicalPerformancePdf()
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Accès restreint.');

        $stats = $this->buildTechnicalStats();

        $pdf = \Pdf::loadView('reports.pdf.technical', $stats)->setPaper('a4', 'landscape');

        return $pdf->download('rapport-technique-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildTechnicalStats(): array
    {
        // Les anciens alias withSum (total_mortality / total_feed_consumed) sont
        // retirés : ils étaient de toute façon MASQUÉS par les accesseurs du
        // modèle (un accesseur prime sur un attribut brut homonyme), donc
        // trompeurs à la lecture — et plus aucun code ne les consomme depuis que
        // le FCR et le taux de mortalité vivent sur Batch.
        $activeBatches = Batch::with('building')
            ->active()
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
        // Seuils UNIQUES (cf. Batch::cumulativeMortalityThreshold) : ce rapport
        // lisait l'ancienne clé en direct, donc ignorait le réglage libellé
        // « mortalité cumulée » que la ferme peut éditer.
        $seuilCritique = \App\Models\Batch::cumulativeMortalityThreshold();
        $seuilAlerte = \App\Models\Batch::cumulativeMortalityWarningThreshold();

        $stats = $activeBatches->map(function ($batch) use ($latestChecks, $seuilCritique, $seuilAlerte) {
            $initial = $batch->initial_quantity;
            $totalMortalite = $batch->total_mortality ?? 0;
            $current = $initial - $totalMortalite;

            // TAUX DE MORTALITÉ — implémentation UNIQUE sur le modèle
            // (Batch::mortality_rate), partagée avec la fiche hebdomadaire.
            //
            // La base de calcul devient (initial_quantity + qty_dead) au lieu de
            // initial_quantity seul : initial_quantity ne compte que les sujets
            // VIVANTS reçus, les morts au transport en sont exclus. Les imputer
            // au numérateur sans les mettre au dénominateur gonflait le taux —
            // un lot de 1 000 sujets commandés dont 20 arrivent morts affichait
            // 2,04 % au lieu de 2,00 %. L'écart est faible mais il s'aggrave avec
            // la mortalité de transport, et deux écrans ne doivent pas donner
            // deux chiffres.
            $tauxMortalite = $batch->mortality_rate;
            // L'ancienne formule était recopiée ici mot pour mot. Depuis que
            // l'âge se compte depuis la NAISSANCE (#292), un lot reçu déjà âgé
            // affichait le bon âge sur sa fiche et le mauvais dans ce rapport —
            // donc un GMQ et des performances faux.
            $age = $batch->age;

            $lastCheck = $latestChecks->get($batch->id);
            $avgWeightGrams = $lastCheck ? ($lastCheck->avg_weight * 1000) : 0;

            // FCR corrigé — implémentation UNIQUE sur le modèle (Batch::
            // fcr_corrected), partagée avec la fiche hebdomadaire par technicien.
            // Formule et bases de calcul inchangées : mêmes valeurs qu'avant.
            $fcr = $batch->fcr_corrected ?? 0;

            return [
                'id'              => $batch->id,
                'code'            => $batch->code,
                'type'            => $batch->type,
                'building'        => $batch->building->name ?? 'N/A',
                'fcr'             => round((float) $fcr, 2),
                'age'             => (int) $age,
                'initial'         => $initial,
                'current'         => $current,
                'mortality_count' => $totalMortalite,
                'mortality_rate'  => round($tauxMortalite, 2),
                'avg_weight'      => $avgWeightGrams,
                'daily_gain'      => $age > 0 ? round($avgWeightGrams / $age, 1) : 0,
                'status'          => $tauxMortalite > $seuilCritique ? 'Critique' : ($tauxMortalite > $seuilAlerte ? 'Alerte' : 'Normal'),
            ];
        });

        return compact('stats');
    }

    /**
     * ANALYSE FINANCIÈRE GLOBALE (Coût de production)
     * Gate : admin.L — données financières sensibles
     *
     * Filtres disponibles :
     *  - year       : année (défaut = année courante)
     *  - status     : all / actif / termine
     *  - month      : 1-12 / all
     *  - species    : id de l'espèce (table species) / all
     *  - date_from  : plage libre (YYYY-MM-DD), prioritaire sur year+month
     *  - date_to    : plage libre (YYYY-MM-DD)
     */
    public function monthlyExpenses(Request $request)
    {
        if (Gate::denies('admin.L')) return back()->with('error', 'Accès réservé.');

        return view('reports.monthly', $this->buildMonthlyExpensesStats($request));
    }

    /**
     * Export PDF de l'analyse financière (Flux de Trésorerie) — filtres en cours appliqués.
     */
    public function monthlyExpensesPdf(Request $request)
    {
        if (Gate::denies('admin.L')) return back()->with('error', 'Accès réservé.');

        $stats = $this->buildMonthlyExpensesStats($request);

        $pdf = \Pdf::loadView('reports.pdf.monthly', $stats)->setPaper('a4', 'landscape');

        return $pdf->download('flux-tresorerie-' . now()->format('Y-m-d') . '.pdf');
    }

    private function buildMonthlyExpensesStats(Request $request): array
    {
        $currentYear   = (int) $request->get('year', date('Y'));
        $statusFilter  = $request->get('status', 'all');
        $monthFilter   = $request->get('month', 'all');
        $speciesFilter = $request->get('species', 'all');
        $dateFrom      = $request->get('date_from');
        $dateTo        = $request->get('date_to');
        $bagWeight     = (float) setting('general.feed_bag_weight', 50);

        // Liste des espèces actives, pour le filtre "Espèce" (couvre toutes les
        // exploitations, pas seulement la volaille — cf. table species).
        $speciesList = Species::active()->orderBy('sort_order')->get();

        // Plage libre : si date_from/date_to fournis, ils priment sur year/month
        $useDateRange = $dateFrom && $dateTo;
        $rangeStart   = $useDateRange ? Carbon::parse($dateFrom)->startOfDay() : null;
        $rangeEnd     = $useDateRange ? Carbon::parse($dateTo)->endOfDay()     : null;

        // ─── LISTE DES ANNÉES DISPONIBLES POUR LE SÉLECTEUR ───
        // Calcul en PHP (et non via YEAR() en SQL) pour rester compatible
        // SQLite (dev/test) et MySQL (prod).
        $availableYears = Batch::whereNotNull('arrival_date')
            ->pluck('arrival_date')
            ->map(fn ($d) => Carbon::parse($d)->year)
            ->unique()->sortDesc()->values()->toArray();
        if (empty($availableYears)) {
            $availableYears = [date('Y')];
        }

        $query = Batch::with(['building', 'feedPurchases'])->live();

        if ($statusFilter === 'actif') {
            $query->active();
        } elseif (in_array($statusFilter, ['termine', 'clos'])) {
            $query->whereIn('status', [Batch::STATUS_TERMINE, Batch::STATUS_CLOTURE]);
        }

        if ($speciesFilter !== 'all') {
            $query->where('species_id', (int) $speciesFilter);
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

        // Le mois est calculé en PHP (et non via MONTH() en SQL) pour rester
        // compatible SQLite (dev/test) et MySQL (prod). L'agrégation par
        // batch/mois se fait via les boucles `+=` ci-dessous.
        $healthData = $healthQuery
            ->select('batch_id', 'cost as total_health', 'intervention_date')
            ->get()
            ->each(fn ($h) => $h->month = Carbon::parse($h->intervention_date)->month);

        $feedConsump = $feedQuery
            ->select('batch_id', 'feed_consumed as qty', 'feed_unit_cost', 'feed_type', 'check_date')
            ->get()
            ->each(fn ($f) => $f->month = Carbon::parse($f->check_date)->month);

        // Carte de repli du coût moyen pondéré courant par article aliment
        // (conso), indexée par nom. Source UNIQUE de valorisation partagée
        // avec la fiche lot (feed_cogs) : on valorise chaque consommation au
        // coût figé à la saisie (feed_unit_cost) et, à défaut (données
        // antérieures au snapshot), au CMP courant de l'article — au lieu de
        // l'ancien prix d'achat moyen qui ignorait l'aliment produit en interne.
        $feedCmpByName = [];
        foreach (\App\Models\Stock::where('category', \App\Models\Stock::CAT_CONSO)->get() as $s) {
            $cmp = (float) ($s->last_unit_price ?? $s->unit_price ?? 0);
            if ($cmp <= 0) continue;
            if ($s->item_name) $feedCmpByName[trim($s->item_name)] = $cmp;
            if ($s->feed_type) $feedCmpByName[trim($s->feed_type)] = $cmp;
        }

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
                if (! isset($monthlyData[$key][$batch->id])) continue;

                // Valorisation : coût figé à la saisie en priorité (cohérent
                // avec la fiche lot), repli sur le CMP courant de l'article,
                // puis sur le prix d'achat moyen du lot en dernier recours.
                $snapshot = (float) ($f->feed_unit_cost ?? 0);
                $unitCost = $snapshot > 0
                    ? $snapshot
                    : ($feedCmpByName[trim((string) $f->feed_type)] ?? $avgPricePerKg);

                $monthlyData[$key][$batch->id]['feed_qty']  += (float) $f->qty;
                $monthlyData[$key][$batch->id]['feed_cost'] += (float) $f->qty * $unitCost;
            }
        }

        // Prix moyen/kg affiché = coût réel valorisé ÷ quantité consommée, afin
        // que la carte reste cohérente avec le coût aliment (et non l'ancien
        // prix d'achat moyen qui pouvait diverger).
        foreach ($monthlyData as $mKey => $mData) {
            foreach ($mData as $bId => $d) {
                $monthlyData[$mKey][$bId]['avg_price_per_kg'] =
                    ($d['feed_qty'] ?? 0) > 0 ? $d['feed_cost'] / $d['feed_qty'] : 0;
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

        return compact(
            'monthlyData', 'months', 'currentYear', 'statusFilter', 'monthFilter',
            'speciesFilter', 'speciesList', 'availableYears', 'globalStats',
            'dateFrom', 'dateTo', 'useDateRange'
        );
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

        // Cibles pisciculture (paramétrables — § Pisciculture des réglages)
        $survivalTarget = (float) setting('pisciculture.taux_survie_cible', 85);
        $fcTarget       = (float) setting('pisciculture.fc_cible', 1.5);
        $cycleDurations = [
            'tilapia' => (int) setting('pisciculture.cycle_tilapia', 0),
            'carpe'   => (int) setting('pisciculture.cycle_carpe', 0),
        ];

        $query = \App\Models\Batch::with(['species', 'building', 'productionType', 'dailyChecks' => function($q) {
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

        $batchStats = $batches->map(function($batch) use ($survivalTarget, $fcTarget, $cycleDurations) {
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

            $lastExt  = $checks->last()?->extension;
            $firstExt = $checks->first()?->extension;

            // IC réel = aliment total consommé / gain de biomasse sur la période suivie
            $totalFeed   = (float) $batch->dailyChecks->sum('feed_consumed');
            $biomassGain = (float) ($lastExt?->biomass_kg ?? 0) - (float) ($firstExt?->biomass_kg ?? 0);
            $fcReal      = $biomassGain > 0 ? round($totalFeed / $biomassGain, 2) : null;

            // Cycle cible (durée de grossissement) selon l'espèce, repli sur le type de production
            $cycleDays = $cycleDurations[$batch->species?->slug] ?? 0;
            if ($cycleDays <= 0) {
                $cycleDays = (int) ($batch->productionType?->cycle_days_default ?? 0);
            }
            $daysRemaining = $cycleDays > 0 ? $cycleDays - $batch->age : null;

            return [
                'batch'           => $batch,
                'series'          => $series,
                'last_ext'        => $lastExt,
                'alerts'          => $lastExt?->getWaterAlerts() ?? [],
                'age_days'        => $batch->age,
                'fc_real'         => $fcReal,
                'fc_target'       => $fcTarget,
                'survival_target' => $survivalTarget,
                'cycle_days'      => $cycleDays > 0 ? $cycleDays : null,
                'days_remaining'  => $daysRemaining,
            ];
        });

        $totalAlerts = $batchStats->sum(fn($s) => count($s['alerts']));
        $criticalCount = $batchStats->sum(fn($s) => collect($s['alerts'])->where('level', 'critical')->count());

        return compact('batchStats', 'statusFilter', 'totalAlerts', 'criticalCount', 'survivalTarget', 'fcTarget');
    }
}
