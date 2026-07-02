<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Protocol;
use App\Models\Provider;
use App\Models\ProductionNorm;
use App\Models\Species;
use App\Actions\Batch\CreateBatch;
use App\Actions\Batch\UpdateBatch;
use App\Actions\Batch\CloseBatch;
use App\Actions\Batch\ReopenBatch;
use App\Http\Requests\Batch\StoreBatchRequest;
use App\Http\Requests\Batch\UpdateBatchRequest;
use App\Http\Requests\Batch\CloseBatchRequest;
use App\Services\BatchQuantityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Controller des lots (bandes) de production.
 *
 * Architecture Phase 4 :
 * - Validation → Form Requests (app/Http/Requests/Batch/)
 * - Logique métier → Actions (app/Actions/Batch/)
 * - Ce controller ne fait QUE le routing HTTP et les réponses
 *
 * Bugs corrigés :
 * - B-01 : scopeCritical SQL au lieu de total_mortalite inexistant
 * - B-02 : BatchService remplacé par CreateBatch Action
 * - B-03/B-04 : UpdateBatch ne touche plus current_quantity
 * - B-07 : CloseBatch calcule la marge complète
 * - B-08 : update protégé par UpdateBatchRequest::authorize()
 * - B-09 : syncAllStocks protégé par Gate::denies('elevage.S')
 * - S-02 : prix aliment calculé depuis feedPurchases (plus hardcodé)
 */
class BatchController extends Controller
{
    /**
     * Liste des bandes actives avec filtrage.
     */
    public function index(Request $request): View
    {
        if (Gate::denies('elevage.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        // 1. On exclut les lots virtuels (œufs) en exigeant des animaux vivants à l'initialisation
        $query = Batch::with(['building', 'provider', 'employee', 'species', 'productionType'])
            ->active()
            ->live();

        $allowedTypes = ['chair', 'ponte', 'poussiniere', 'reproducteur'];

        // Groupes d'espèces pour le filtre multiespèce
        $familyGroups = [
            'volaille'    => ['volaille'],
            'ruminants'   => ['petit_ruminant', 'grand_ruminant'],
            'aquaculture' => ['aquaculture'],
            'autres'      => ['porcin', 'lagomorphe', 'autre'],
        ];

        $applyFamilyFilter = function ($q, array $families, string $group) {
            if ($group === 'volaille') {
                $q->where(function ($sub) use ($families) {
                    $sub->whereNull('species_id')
                        ->orWhereHas('species', fn($s) => $s->whereIn('family', $families));
                });
            } else {
                $q->whereHas('species', fn($s) => $s->whereIn('family', $families));
            }
        };

        // Filtre surmortalité
        $isCriticalView = $request->query('view') === 'critical';
        if ($isCriticalView) {
            $query->critical(); // seuil unifié (Batch::cumulativeMortalityThreshold)
        }

        // Filtre par famille d'espèce
        $familyFilter = $request->input('family');
        if ($familyFilter && isset($familyGroups[$familyFilter])) {
            $applyFamilyFilter($query, $familyGroups[$familyFilter], $familyFilter);
        }

        // Filtre par type : sous-filtre de la volaille uniquement (chair/ponte/…).
        // On ne l'applique que dans le contexte volaille, en cohérence avec
        // l'affichage des onglets de type (sinon une URL obsolète ?type=chair
        // sans famille filtrerait toutes les espèces sans onglet actif visible).
        if ($familyFilter === 'volaille'
            && $request->filled('type')
            && in_array($request->type, $allowedTypes)) {
            $query->byType($request->type);
        }

        // 2. On applique la même exclusion pour que les compteurs d'onglets soient justes
       // $baseQuery = Batch::active()->where('initial_quantity', '>', 0);
        $baseQuery = Batch::active()->live();

        if ($isCriticalView) {
            $baseQuery->critical(); // seuil unifié (Batch::cumulativeMortalityThreshold)
        }

        // Compteurs des onglets "famille" : indépendants du filtre famille en cours
        $familyCounts = [];
        foreach ($familyGroups as $group => $families) {
            $groupQuery = clone $baseQuery;
            $applyFamilyFilter($groupQuery, $families, $group);
            $familyCounts[$group] = $groupQuery->count();
        }

        // Compteurs des onglets "type" : tiennent compte du filtre famille en cours
        if ($familyFilter && isset($familyGroups[$familyFilter])) {
            $applyFamilyFilter($baseQuery, $familyGroups[$familyFilter], $familyFilter);
        }

        $counts = [
            'all'          => (clone $baseQuery)->count(),
            'chair'        => (clone $baseQuery)->byType('chair')->count(),
            'ponte'        => (clone $baseQuery)->byType('ponte')->count(),
            'reproducteur' => (clone $baseQuery)->byType('reproducteur')->count(),
            'poussiniere'  => (clone $baseQuery)->byType('poussiniere')->count(),
        ];

        $batches = $query->orderBy('arrival_date', 'desc')->paginate((int) setting('general.items_per_page', 20));
        $batches->appends($request->all());

        return view('batches.index', compact('batches', 'counts', 'familyCounts', 'familyFilter'));
    }
    /**
     * Archives des lots clôturés.
     */
    public function archives(Request $request): View
    {
        if (Gate::denies('elevage.L')) {
            abort(403, 'Accès restreint.');
        }

        $query = Batch::with(['building', 'provider'])->archived();

        if ($request->filled('building_id')) {
            $request->validate(['building_id' => 'integer|exists:buildings,id']);
            $query->where('building_id', $request->building_id);
        }

        $archivedBatches = $query->orderBy('closing_date', 'desc')->paginate((int) setting('general.items_per_page', 20));
        
        // 👈 Ajout du scope physical() et d'un tri par nom pour l'UX
        $buildings = Building::physical()->select('id', 'name')->orderBy('name')->get();

        return view('batches.archives', compact('archivedBatches', 'buildings'));
    }

    /**
     * Formulaire de création.
     */
    public function create(): View
    {
        if (Gate::denies('elevage.C')) {
            abort(403, 'Privilèges insuffisants.');
        }

        $buildings   = Building::physical()->orderBy('name')->get();
        // species_id permet de filtrer les souches par espèce côté client.
        $normModels  = ProductionNorm::with('species:id,slug')
            ->select('species_id', 'model_name', 'batch_type')->distinct()->get();
        $protocols   = Protocol::all();
        $employees   = Employee::where('status', 'Actif')->orderBy('last_name')->get();
        $providers   = Provider::where('status', 'Actif')->orderBy('name')->get();
        $activeSpecies = Species::active()->with('productionTypes:id,species_id,slug,name_fr,cycle_days_default,kpi_primary')
            ->orderBy('sort_order')->get();

        return view('batches.create', compact('buildings', 'normModels', 'protocols', 'employees', 'providers', 'activeSpecies'));
    }

    /**
     * Enregistrement d'un nouveau lot.
     *
     * Validation : StoreBatchRequest (gère Gate, règles conditionnelles repro/standard)
     * Logique : CreateBatch Action (capacité, coûts, planning sanitaire)
     */
    public function store(StoreBatchRequest $request, CreateBatch $action): RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            abort(403, 'Privilèges insuffisants.');
        }

        $batch = $action->execute($request->validated());

        return redirect()->route('batches.show', $batch)
            ->with('success', "Lot {$batch->code} lancé avec succès.");
    }

    /**
     * Fiche détaillée d'un lot.
     */
    public function show(Batch $batch): View
    {
        if (Gate::denies('elevage.L')) {
            abort(403, 'Accès restreint.');
        }
        $batch->load([
            'building', 'protocol.steps', 'healthChecks',
            'feedPurchases', 'tasks', 'species.productionTypes', 'productionType',
            'dailyChecks' => fn($q) => $q->orderBy('check_date', 'asc'),
            'dailyChecks.extension',
        ]);

        $buildings = Building::physical()->orderBy('name')->get();
        $protocols = Protocol::withCount('steps')->get();
        $providers = Provider::orderBy('name')->get();

        // Calculs de performance (S-02 corrigé : coût réel depuis feedPurchases)
        $totalFeedKg = $batch->dailyChecks->sum('feed_consumed');
        $totalFeedCost = (float) $batch->feedPurchases->sum('total_price');
        $totalHealthCost = (float) $batch->healthChecks->sum('cost');

        $stats = [
            'total_feed'       => $totalFeedKg,
            'total_feed_cost'  => $totalFeedCost,
            'total_health_cost'=> $totalHealthCost,
            'fcr'              => $batch->fcr,
            'mortality_rate'   => $batch->mortality_rate,
            'age'              => $batch->age,
            'current_phase'    => $batch->current_phase,
            'net_margin'       => $batch->net_margin,
            'feed_cogs'        => $batch->feed_cogs,
            'utility_cost'     => $batch->utility_cost,
            'manure_collected_kg'      => $batch->manure_collected_kg,
            'estimated_manure_revenue' => $batch->estimated_manure_revenue,
        ];

        // GMQ stats (ruminants, porcins, lapins)
        if ($batch->isGmqTracked()) {
            $checksWithWeight = $batch->dailyChecks->filter(fn($c) => $c->avg_weight > 0)->values();
            $gmq = null;
            if ($checksWithWeight->count() >= 2) {
                $first = $checksWithWeight->first();
                $last  = $checksWithWeight->last();
                $days  = max(1, \Carbon\Carbon::parse($first->check_date)->diffInDays($last->check_date));
                $gmq   = round((($last->avg_weight - $first->avg_weight) * 1000) / $days);
            }
            $totalBorn   = $batch->dailyChecks->sum(fn($c) => $c->extension?->qty_born ?? 0);
            $totalWeaned = $batch->dailyChecks->sum(fn($c) => $c->extension?->qty_weaned ?? 0);
            $birthEvents = $batch->dailyChecks->filter(fn($c) => ($c->extension?->qty_born ?? 0) > 0)->count();

            $stats['gmq']             = $gmq;
            $stats['total_born']      = $totalBorn;
            $stats['total_weaned']    = $totalWeaned;
            $stats['birth_events']    = $birthEvents;
            $stats['avg_litter_size'] = $birthEvents > 0 ? round($totalBorn / $birthEvents, 1) : null;
            $stats['weaning_rate']    = $totalBorn > 0 ? round(($totalWeaned / $totalBorn) * 100, 1) : null;
        }

        // Aquaculture stats
        if ($batch->isAquaculture()) {
            $lastExt = $batch->dailyChecks->sortByDesc('check_date')
                ->firstWhere(fn($c) => $c->extension !== null)?->extension;
            $stats['last_water_temp']    = $lastExt?->water_temp;
            $stats['last_water_ph']      = $lastExt?->water_ph;
            $stats['last_water_o2']      = $lastExt?->water_o2_ppm;
            $stats['last_water_ammonia'] = $lastExt?->water_ammonia_ppm;
            $stats['water_alerts']       = $lastExt?->getWaterAlerts() ?? [];
            $stats['last_biomass']       = $lastExt?->biomass_kg;
            $stats['last_survival_rate'] = $lastExt?->survival_rate;
        }

        // Recommandations intelligentes (dosage aliment/eau ajusté à l'âge, au
        // poids, à l'effectif et aux conditions d'ambiance) + conseils dérivés.
        $advisor         = new \App\Services\BatchAdvisorService();
        $feedAdvice      = $advisor->recommendation($batch);
        $batchAdvisories = $advisor->advisories($batch);
        $feedAutonomy    = $advisor->feedAutonomy($batch);
        $weightCurve     = $advisor->weightCurve($batch);

        // Souches disponibles pour le modal de mutation (graduation de phase).
        $normModels = ProductionNorm::forSpecies($batch->species_id)
            ->select('model_name', 'batch_type')
            ->distinct()
            ->orderBy('batch_type')
            ->orderBy('model_name')
            ->get();

        return view('batches.show', compact('batch', 'buildings', 'protocols', 'providers', 'stats', 'feedAdvice', 'batchAdvisories', 'feedAutonomy', 'normModels', 'weightCurve'));
    }

    /**
     * Formulaire d'édition.
     */
    public function edit(Batch $batch): View
    {
        if (Gate::denies('elevage.M')) {
            abort(403, 'Modification interdite.');
        }

        $batch->load('species.productionTypes');

        $buildings     = Building::physical()->orderBy('name')->get();
        $employees     = Employee::where('status', 'Actif')->get();
        $providers     = Provider::where('status', 'Actif')->get();
        $protocols     = Protocol::all();
        // Souches limitées à l'espèce du lot (+ souches génériques sans espèce).
        $normModels    = ProductionNorm::forSpecies($batch->species_id)
            ->select('species_id', 'batch_type', 'model_name')->distinct()->get();
        $activeSpecies = Species::active()->with('productionTypes:id,species_id,slug,name_fr,cycle_days_default,kpi_primary')
            ->orderBy('sort_order')->get();

        return view('batches.edit', compact('batch', 'buildings', 'providers', 'employees', 'protocols', 'normModels', 'activeSpecies'));
    }

    /**
     * Mise à jour d'un lot.
     *
     * B-03/B-04 corrigés : UpdateBatch ne touche JAMAIS current_quantity.
     * B-08 corrigé : autorisation dans UpdateBatchRequest::authorize().
     */
    public function update(UpdateBatchRequest $request, Batch $batch, UpdateBatch $action): RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            abort(403, 'Modification interdite.');
        }
        $action->execute($batch, $request->validated());

        return redirect()->route('batches.show', $batch)
            ->with('success', 'Lot mis à jour.');
    }

    /**
     * Formulaire de clôture.
     */
    

    public function showCloseForm(Batch $batch)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        $batch->load('building');

        $remainingBirds = (int) $batch->current_quantity;
        $totalMortality = (int) ($batch->initial_quantity - $batch->current_quantity);

        // ═══ COÛTS DÉTAILLÉS ═══

        // 1. Coût d'acquisition (poussins)
        $acquisitionCost = (float) $batch->total_acquisition_cost;
        if ($acquisitionCost <= 0) {
            $acquisitionCost = (float) $batch->buy_price_per_unit * (int) $batch->initial_quantity;
        }

        // 2. Coût alimentation (somme des daily_checks.feed_quantity × prix unitaire aliment)
        $feedData = \App\Models\DailyCheck::where('batch_id', $batch->id)
            ->selectRaw('SUM(feed_consumed) as total_kg')
            ->first();
        $totalFeed = (float) ($feedData->total_kg ?? 0);

        // Prix moyen du kg d'aliment (depuis les stocks de type conso),
        // filtré sur le secteur d'aliment du lot (Chair/Ponte) via feedSector().
        $avgFeedPrice = \App\Models\Stock::where('category', \App\Models\Stock::CAT_CONSO)
            ->where('item_name', 'LIKE', '%' . $batch->feedSector() . '%')
            ->avg('last_unit_price') ?? 0;

        // Si pas de prix moyen, estimer depuis les achats
        if ($avgFeedPrice <= 0) {
            $avgFeedPrice = \App\Models\Stock::where('category', \App\Models\Stock::CAT_CONSO)
                ->where('last_unit_price', '>', 0)
                ->avg('last_unit_price') ?? 5000; // Fallback 5000 GNF/kg
        }

        $feedCost = $totalFeed * $avgFeedPrice;

        // 3. Coût santé / vétérinaire
        $healthCost = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('health_checks')) {
            $healthCost = (float) \Illuminate\Support\Facades\DB::table('health_checks')
                ->where('batch_id', $batch->id)
                ->sum('cost');
        }

        // 4. Coût énergie (proportionnel au lot si multi-lots)
        $energyCost = 0;
        $durationDays = $batch->arrival_date
            ? \Carbon\Carbon::parse($batch->arrival_date)->diffInDays(now())
            : 0;

        if ($durationDays > 0 && \Illuminate\Support\Facades\Schema::hasTable('energy_readings')) {
            $totalDailyEnergyCost = (float) \Illuminate\Support\Facades\DB::table('energy_readings')
                ->where('reading_date', '>=', $batch->arrival_date)
                ->avg('cost') ?? 0;

            // Proportion : si 3 lots actifs, ce lot = 1/3 du coût énergie
            $activeBatchCount = max(1, \App\Models\Batch::active()->live()->count());
            $energyCost = ($totalDailyEnergyCost * $durationDays) / $activeBatchCount;
        }

        // 5. Total des coûts connus
        $totalKnownCosts = $acquisitionCost + $feedCost + $healthCost + $energyCost;

        // Données pour la vue
        $costs = [
            'acquisition'     => round($acquisitionCost),
            'feed'            => round($feedCost),
            'feed_kg'         => round($totalFeed, 1),
            'feed_price_kg'   => round($avgFeedPrice),
            'health'          => round($healthCost),
            'energy'          => round($energyCost),
            'total_known'     => round($totalKnownCosts),
            'duration_days'   => $durationDays,
        ];

        return view('batches.close', compact(
            'batch', 'remainingBirds', 'totalMortality', 'totalFeed', 'costs'
        ));
    }

    /**
     * Clôture d'un lot.
     *
     * B-07 corrigé : CloseBatch calcule revenus + coûts complets.
     */
    public function close(CloseBatchRequest $request, Batch $batch, CloseBatch $action): RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            abort(403, 'Clôture interdite.');
        }

        try {
            $result = $action->execute($batch, $request->validated());
        } catch (\DomainException $e) {
            // Ex. lot déjà clôturé : refus métier propre (audit W1), jamais un 500.
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('batches.index')
            ->with('success',
                "Lot {$batch->code} clôturé. " .
                "Revenu : " . number_format($result->total_revenue) . " " . currency() . ". " .
                "Marge : " . number_format($result->margin) . " " . currency() . "."
            );
    }

    /**
     * Réouverture d'un lot clôturé.
     *
     * S-03 corrigé : recalcul de l'effectif depuis les daily_checks.
     */
    public function reopen(Batch $batch, ReopenBatch $action): RedirectResponse
    {
        if (Gate::denies('elevage.S')) {
            return back()->with('error', 'Réouverture réservée aux administrateurs.');
        }

        try {
            $result = $action->execute($batch);
        } catch (\DomainException $e) {
            // Ex. lot déjà actif : refus métier propre (audit W1), jamais un 500.
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('batches.show', $batch)
            ->with('success', "Lot {$batch->code} réouvert. Effectif recalculé : {$result->current_quantity} sujets.");
    }

    /**
     * Synchronisation ERP : recalcul de tous les effectifs.
     *
     * B-09 corrigé : protégé par Gate 'S'.
     */
    public function syncAllStocks(BatchQuantityService $service): RedirectResponse
    {
        if (Gate::denies('elevage.S')) {
            return back()->with('error', 'Action réservée aux administrateurs.');
        }

        $report = $service->rebuildAll();

        return back()->with('success',
            "Synchronisation terminée : {$report['total_checked']} lots vérifiés, " .
            "{$report['total_corrected']} corrigés."
        );
    }

    /**
     * Suppression (soft-delete).
     */
    public function destroy(Batch $batch): RedirectResponse
    {
        if (Gate::denies('elevage.S')) {
            abort(403, 'Privilèges insuffisants.');
        }

        $building = $batch->building;
        $code = $batch->code;

        $batch->delete();

        // Mise à jour statut bâtiment si plus de lots actifs
        if ($building && ! Batch::where('building_id', $building->id)->active()->exists()) {
            $building->update(['status' => Building::STATUS_VIDE]);
        }

        return redirect()->route('batches.index')
            ->with('success', "Lot {$code} archivé.");
    }

    /**
     * Endpoint JSON pour le miroir IndexedDB (offline).
     */
    public function getOfflineBatches(Request $request)
    {
        if (Gate::denies('elevage.L')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(
            Batch::active()
                ->live() // exclut les lots virtuels (œufs externes, initial_quantity=0)
                ->when($request->query('since'), function ($q, $since) {
                    $q->where('updated_at', '>=', $since);
                })
                ->with('productionType:id,slug')
                ->get(['id', 'uuid', 'code', 'building_id', 'current_quantity', 'production_type_id', 'updated_at'])
                ->map(fn (Batch $batch) => [
                    'id' => $batch->id,
                    'uuid' => $batch->uuid,
                    'code' => $batch->code,
                    'building_id' => $batch->building_id,
                    'current_quantity' => $batch->current_quantity,
                    'type' => $batch->type,
                    'updated_at' => $batch->updated_at,
                ])
        );
    }

    /**
     * Endpoint de synchronisation (offline → serveur).
     */
    public function sync(Request $request, CreateBatch $action)
    {
        if (Gate::denies('elevage.C')) {
            return response()->json(['status' => 'error', 'message' => 'Non autorisé.'], 403);
        }

        $request->validate(['uuid' => 'required|uuid']);

        // SÉCURITÉ : ne jamais accepter farm_id du client (cross-tenant) ni de
        // jeton CSRF en données métier — le farm_id est posé côté serveur par le
        // trait BelongsToFarm. On ne propage donc qu'un payload assaini.
        $payload = $request->except(['_token', 'farm_id', 'id']);

        try {
            // Vérifier si le lot existe déjà (par UUID)
            $existing = Batch::where('uuid', $request->uuid)->first();

            if ($existing) {
                // Mise à jour — on utilise UpdateBatch pour ne pas écraser current_quantity
                $updateAction = app(UpdateBatch::class);
                $batch = $updateAction->execute($existing, $payload);
            } else {
                $batch = $action->execute($payload);
            }

            return response()->json([
                'status' => 'success',
                'uuid'   => $batch->uuid,
                'message' => 'Données synchronisées.',
            ]);
        } catch (\Throwable $e) {
            // On journalise le détail côté serveur et on renvoie un message générique
            // (ne jamais exposer la trace/SQL au client).
            Log::error("BatchController::sync échec (uuid={$request->uuid}) : " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => 'Synchronisation impossible pour cet enregistrement.',
            ], 422);
        }
    }
}
