<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Stock;
use App\Actions\DailyCheck\RecordDailyCheck;
use App\Actions\DailyCheck\SyncManureCollection;
use App\Http\Requests\DailyCheck\StoreDailyCheckRequest;
use App\Http\Requests\DailyCheck\UpdateDailyCheckRequest;
use App\Services\StockIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Controller des pointages journaliers.
 *
 * Bugs corrigés :
 * - S-10 : suppression du try/catch PDO (le faux mode offline)
 * - B-13 : l'Action utilise updateOrCreate avec UNIQUE index garanti (Phase 1)
 * - S-11 : lockForUpdate géré par DailyCheckObserver (Phase 2)
 */
class DailyCheckController extends Controller
{
    /**
     * Liste des pointages.
     */
    public function index(): View
    {
        if (Gate::denies('elevage.L')) {
            abort(403, 'Accès restreint.');
        }

        $dailyChecks = DailyCheck::with('batch.building')
            ->latest('check_date')
            ->paginate((int) setting('general.items_per_page', 20));

        return view('daily-checks.index', compact('dailyChecks'));
    }

    /**
     * Formulaire de création d'un pointage.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', 'Action non autorisée.');
        }
        $batchId = $request->query('batch_id');

        if (! $batchId) {
            return redirect()->route('batches.index')
                ->with('error', 'Aucun lot spécifié.');
        }

        $batch = Batch::with(['building', 'protocol.steps'])->findOrFail($batchId);

        // Préparation des phases aliment et stocks disponibles, selon le
        // secteur du lot (cf. Batch::feedSector()/feedPhases()).
        $phases = $batch->feedPhases();

        $stockData = [];
        foreach ($phases as $phase) {
            // 1. Recherche par la nouvelle clé stricte
            $item = Stock::where('feed_type', $phase)
                ->where('category', Stock::CAT_CONSO)
                ->first();
                
            // 2. Conversion automatique en KG
            if ($item) {
                $stockData[$phase] = (strtolower($item->unit) === 'sac') 
                    ? (float) $item->current_quantity * 50 
                    : (float) $item->current_quantity;
            } else {
                $stockData[$phase] = 0;
            }
        }

        // Pré-remplissage météo : priorité au relevé du jour de la ferme (rempli
        // par weather:fetch) ; sinon repli sur une récupération live mise en
        // cache. Sert à fiabiliser le THI (BatchAdvisorService::environment).
        $weather = $this->suggestedWeather($batch);

        // Dose recommandée du jour : barème de la souche interpolé à la semaine
        // d'âge puis ajusté à l'environnement (chaleur/saison), identique à la
        // « Recommandation du jour » de la fiche lot. Source unique de vérité
        // (BatchAdvisorService) au lieu d'une moyenne glissante approximative.
        $advisor = new \App\Services\BatchAdvisorService();
        $recommendation = $advisor->recommendation($batch);
        $suggestedFeed = $recommendation['total']['feed_kg'] ?? null;

        return view('daily-checks.create', compact('batch', 'stockData', 'phases', 'weather', 'suggestedFeed'));
    }

    /**
     * Météo suggérée pour pré-remplir le pointage (temp min/max + humidité).
     *
     * @return array{temp_min: ?float, temp_max: ?float, humidity: ?float, label: ?string}|null
     */
    private function suggestedWeather(Batch $batch): ?array
    {
        $farmId = $batch->farm_id ?? session('current_farm_id') ?? \App\Models\Farm::defaultId();

        // 1. Relevé agronomique du jour déjà en base (aucun appel réseau).
        $reading = \App\Models\WeatherReading::where('farm_id', $farmId)
            ->whereDate('reading_date', now()->toDateString())
            ->latest('id')
            ->first();

        if ($reading) {
            return [
                'temp_min' => $reading->temperature_min !== null ? (float) $reading->temperature_min : null,
                'temp_max' => $reading->temperature_max !== null ? (float) $reading->temperature_max : null,
                'humidity' => $reading->humidity_pct !== null ? (float) $reading->humidity_pct : null,
                'label'    => 'relevé du jour',
            ];
        }

        // 2. Repli live (mis en cache 1 h) — jamais bloquant pour le formulaire.
        $farm = $farmId ? \App\Models\Farm::find($farmId) : null;
        if (! $farm) {
            return null;
        }

        $live = app(\App\Services\WeatherService::class)->currentForFarm($farm);

        return $live ? array_merge($live, ['label' => $live['label'] ?? 'météo du jour']) : null;
    }

    /**
     * Enregistrement d'un pointage.
     *
     * La logique métier (stock aliment, updateOrCreate, compensation) est dans RecordDailyCheck.
     * L'impact sur current_quantity est dans DailyCheckObserver.
     */
    public function store(StoreDailyCheckRequest $request, RecordDailyCheck $action): RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', 'Action non autorisée.');
        }
        $check = $action->execute($request->validated());

        // Save species-specific extension if applicable
        if ($check->batch->isGmqTracked() || $check->batch->isAquaculture()) {
            $extData = [];

            if ($check->batch->isGmqTracked()) {
                $extData = array_merge($extData, [
                    'qty_born'     => $request->integer('ext_qty_born', 0),
                    'qty_weaned'   => $request->integer('ext_qty_weaned', 0),
                    'milk_liters'  => $request->input('ext_milk_liters'),
                    'milk_fat_pct' => $request->input('ext_milk_fat_pct'),
                ]);
            }

            if ($check->batch->isAquaculture()) {
                $extData = array_merge($extData, [
                    'water_temp'        => $request->input('ext_water_temp'),
                    'water_ph'          => $request->input('ext_water_ph'),
                    'water_o2_ppm'      => $request->input('ext_water_o2_ppm'),
                    'water_ammonia_ppm' => $request->input('ext_water_ammonia_ppm'),
                    'biomass_kg'        => $request->input('ext_biomass_kg'),
                    'survival_rate'     => $request->input('ext_survival_rate'),
                ]);
            }

            if (!empty(array_filter($extData, fn($v) => $v !== null))) {
                \App\Models\DailyCheckExtension::updateOrCreate(
                    ['daily_check_id' => $check->id],
                    $extData
                );
            }
        }

        return redirect()->route('batches.show', $check->batch_id)
            ->with('success', 'Pointage enregistré et stock mis à jour.');
    }

    /**
     * Formulaire d'édition.
     */
    public function edit(DailyCheck $daily_check): View|RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Modification interdite.');
        }

        $check = $daily_check->load(['batch.species', 'extension']);

        if ($check->batch->status !== 'Actif') {
            return redirect()->route('batches.show', $check->batch_id)
                ->with('error', 'Lot clôturé : modification impossible.');
        }

        // PRÉPARATION DES STOCKS POUR LA VUE (Évite les requêtes DB dans le Blade)
        $phases = $check->batch->feedPhases();

        $stockData = [];
        foreach ($phases as $phase) {
            $item = Stock::where('feed_type', $phase) // Utilisation propre de la façade importée
                ->where('category', Stock::CAT_CONSO)
                ->first();
                
            if ($item) {
                $stockData[$phase] = (strtolower($item->unit) === 'sac') 
                    ? (float) $item->current_quantity * 50 
                    : (float) $item->current_quantity;
            } else {
                $stockData[$phase] = 0;
            }
        }

        return view('daily-checks.edit', compact('check', 'phases', 'stockData'));
    }

    /**
     * Mise à jour d'un pointage.
     *
     * Gère la compensation de stock aliment et le recalcul d'impact sur le lot.
     * Le DailyCheckObserver gère la mise à jour de current_quantity.
     */
    public function update(UpdateDailyCheckRequest $request, DailyCheck $daily_check): RedirectResponse
    {
        $check = $daily_check;
        $batch = $check->batch;

        $validated = $request->validated();

        // Vérification effectif
        $oldImpact = $check->calculateNetImpact();
        $newImpact = ((int) $validated['mortality'] + (int) $validated['qty_quarantine_in'] + (int) ($validated['qty_sorted_out'] ?? 0))
                   - (int) $validated['qty_quarantine_out'];
        $diff = $newImpact - $oldImpact;

        if (($batch->current_quantity - $diff) < 0) {
            return back()->withErrors(['mortality' => "L'effectif du lot deviendrait négatif."])->withInput();
        }

        // Vérification stock aliment
        $availableKg = $this->getAvailableStockInKg($validated['feed_type']);
        if (trim($check->feed_type) === trim($validated['feed_type'])) {
            $availableKg += (float) $check->feed_consumed;
        }
        if ($validated['feed_consumed'] > $availableKg) {
            return back()->withErrors([
                'feed_consumed' => "Stock insuffisant pour {$validated['feed_type']}. Disponible : " . number_format($availableKg, 1) . " kg",
            ])->withInput();
        }

        // Fumier : quantité avant rectification, pour compensation du stock.
        $oldManure = (float) $check->manure_collected_kg;
        $newManure = (float) ($validated['manure_collected_kg'] ?? 0);

        return DB::transaction(function () use ($request, $check, $batch, $validated, $oldManure, $newManure) {
            // Restitution de l'ancien stock
            if ((float) $check->feed_consumed > 0) {
                StockIntegrationService::syncMovement(
                    $check->feed_type, 'conso', (float) $check->feed_consumed, 'in',
                    "Rectification pointage #{$check->id} (annulation)", 'KG'
                );
            }

            // Nouveau mouvement de sortie
            if ((float) $validated['feed_consumed'] > 0) {
                StockIntegrationService::syncMovement(
                    $validated['feed_type'], 'conso', (float) $validated['feed_consumed'], 'out',
                    "Rectification pointage #{$check->id} (nouvelle conso)", 'KG'
                );
            }

            // litter_changed et qty_sorted_out sont déjà normalisés par
            // UpdateDailyCheckRequest::prepareForValidation().

            // Re-snapshot du coût de revient de l'aliment consommé (CMP courant),
            // pour que la rectification revalorise correctement la marge du lot.
            if ((float) $validated['feed_consumed'] > 0) {
                $name = trim($validated['feed_type']);
                $stock = Stock::where('category', Stock::CAT_CONSO)
                    ->where(fn ($q) => $q->where('item_name', $name)->orWhere('feed_type', $name))
                    ->first();
                $validated['feed_unit_cost'] = (float) ($stock?->last_unit_price ?? $stock?->unit_price ?? 0);
            } else {
                $validated['feed_unit_cost'] = 0;
            }

            // L'observer DailyCheckObserver gère le diff sur current_quantity
            $check->update($validated);

            // Compensation du stock fumier (restitue l'ancien ramassage,
            // applique le nouveau) pour ne pas double-compter le fertilisant.
            app(SyncManureCollection::class)->execute($batch, $oldManure, $newManure);

            // Save species-specific extension if applicable
            if ($check->batch->isGmqTracked() || $check->batch->isAquaculture()) {
                $extData = [];

                if ($check->batch->isGmqTracked()) {
                    $extData = array_merge($extData, [
                        'qty_born'     => $request->integer('ext_qty_born', 0),
                        'qty_weaned'   => $request->integer('ext_qty_weaned', 0),
                        'milk_liters'  => $request->input('ext_milk_liters'),
                        'milk_fat_pct' => $request->input('ext_milk_fat_pct'),
                    ]);
                }

                if ($check->batch->isAquaculture()) {
                    $extData = array_merge($extData, [
                        'water_temp'        => $request->input('ext_water_temp'),
                        'water_ph'          => $request->input('ext_water_ph'),
                        'water_o2_ppm'      => $request->input('ext_water_o2_ppm'),
                        'water_ammonia_ppm' => $request->input('ext_water_ammonia_ppm'),
                        'biomass_kg'        => $request->input('ext_biomass_kg'),
                        'survival_rate'     => $request->input('ext_survival_rate'),
                    ]);
                }

                if (!empty(array_filter($extData, fn($v) => $v !== null))) {
                    \App\Models\DailyCheckExtension::updateOrCreate(
                        ['daily_check_id' => $check->id],
                        $extData
                    );
                }
            }

            return redirect()->route('batches.show', $check->batch_id)
                ->with('success', 'Pointage et stocks rectifiés.');
        });
    }

    /**
     * Suppression d'un pointage.
     */
    public function destroy(DailyCheck $daily_check): RedirectResponse
    {
        if (Gate::denies('elevage.S')) {
            return back()->with('error', 'Suppression réservée aux administrateurs.');
        }

        $check = $daily_check;
        $batchId = $check->batch_id;

        return DB::transaction(function () use ($check, $batchId) {
            // Restitution du stock aliment
            if ((float) $check->feed_consumed > 0) {
                StockIntegrationService::syncMovement(
                    $check->feed_type, 'conso', (float) $check->feed_consumed, 'in',
                    "Suppression pointage - Restitution stock", 'KG'
                );
            }

            // Restitution du stock fumier (le ramassage supprimé sort du stock).
            if ((float) $check->manure_collected_kg > 0 && $check->batch) {
                app(SyncManureCollection::class)->execute($check->batch, (float) $check->manure_collected_kg, 0);
            }

            // L'observer DailyCheckObserver gère la restitution de current_quantity
            $check->delete();

            return redirect()->route('batches.show', $batchId)
                ->with('success', 'Pointage supprimé et stocks restitués.');
        });
    }

    /**
     * Helper : stock disponible en KG pour un type d'aliment.
     */
    private function getAvailableStockInKg(string $feedType): float
    {
        // 1. Recherche stricte
        $stock = Stock::where('feed_type', trim($feedType))
            ->where('category', Stock::CAT_CONSO)
            ->first();

        if (!$stock) {
            return 0;
        }

        // 2. Conversion en KG
        return (strtolower($stock->unit) === 'sac') 
            ? (float) $stock->current_quantity * 50 
            : (float) $stock->current_quantity;
    }
}
