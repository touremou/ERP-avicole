<?php

namespace App\Services\Sync;

use App\Actions\Crop\RecordCropInput;
use App\Actions\Crop\RecordHarvest;
use App\Actions\DailyCheck\RecordDailyCheck;
use App\Actions\EggProduction\RecordEggCollection;
use App\Actions\Expense\CreateExpense;
use App\Actions\MillProduction\CompleteMillProduction;
use App\Actions\Sale\CreateSale;
use App\Actions\Stock\MoveStockAction;
use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\DailyCheck;
use App\Models\EggProduction;
use App\Models\Harvest;
use App\Models\HealthIncident;
use App\Models\Expense;
use App\Models\MillProduction;
use App\Models\Sale;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\SlaughterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * SyncService — porte d'entrée UNIQUE de la réconciliation offline (API v1).
 *
 * Fusion décidée par l'audit 360° (§1.1-A2) et spécifiée dans
 * docs/mobile/phase-0-spec.md §4-5 : l'ancien SyncController (web) n'était
 * routé nulle part et DIVERGEAIT déjà des Actions métier (son
 * reconcileDailyCheck ne compensait ni l'aliment ni le fumier/l'eau, et
 * gardait les lots derrière les Gates admin.* au lieu d'elevage.*).
 *
 * Principes conservés (et testés dans ApiSyncTest) :
 *  - IDEMPOTENCE par uuid généré côté terrain — doublée d'index UNIQUE en base
 *    (migration 2026_07_02_000001) : le rejeu renvoie `already_synced` ;
 *  - CONFLITS métier non rejouables → `conflict` (jour déjà pointé/tirié,
 *    stock insuffisant, version serveur plus récente) ;
 *  - la logique métier reste dans les Actions partagées — ce service ne fait
 *    qu'orchestrer permissions, idempotence et statuts ;
 *  - opérations sensibles créées en BROUILLON/EN ATTENTE (vente, dépense) :
 *    la validation reste une opération en ligne.
 *
 * Statuts renvoyés : success | already_synced | conflict |
 *                    permission_denied | validation_failed | error.
 */
class SyncService
{
    /**
     * Avance d'horloge tolérée sur un horodatage déclaré par le terrain.
     *
     * Les téléphones du terrain dérivent : refuser une saisie parce que l'appareil
     * a trente secondes d'avance la condamne au bac « À corriger », d'où elle ne
     * revient pas. Quinze minutes couvrent toute dérive plausible sans laisser
     * dater un acte à un moment qui n'est pas encore arrivé.
     */
    private const CLOCK_SKEW_TOLERANCE = '+15 minutes';

    /**
     * Registre type d'opération → handler.
     *
     * @return array<string, string> méthode locale par type
     */
    public static function types(): array
    {
        return [
            'daily_check.create'     => 'dailyCheckCreate',
            'egg_collection.create'  => 'eggCollectionCreate',
            'stock_movement.create'  => 'stockMovementCreate',
            'water_refill.create'    => 'waterRefillCreate',
            'sale.create'            => 'saleCreate',
            'payment.create'         => 'paymentCreate',
            'sale_return.create'     => 'saleReturnCreate',
            'inventory_count.create' => 'inventoryCountCreate',
            'feed_purchase.create'   => 'feedPurchaseCreate',
            'mill_production.create' => 'millProductionCreate',
            'incubation.mirage'      => 'incubationMirage',
            'incubation.hatch'       => 'incubationHatch',
            'milk_production.create' => 'milkProductionCreate',
            'energy_reading.create'  => 'energyReadingCreate',
            'water_reading.create'   => 'waterReadingCreate',
            'attendance.create'      => 'attendanceCreate',
            'expense.create'         => 'expenseCreate',
            'batch.upsert'           => 'batchUpsert',
            'health_incident.create' => 'healthIncidentCreate',
            'health_check.create'    => 'healthCheckCreate',
            // Phase 3 — cultures, abattoir, provenderie (rfc-cadrage §MoSCoW).
            'crop_cycle.create'       => 'cropCycleCreate',
            'harvest.create'          => 'harvestCreate',
            'crop_input.create'       => 'cropInputCreate',
            'crop_transformation.create' => 'cropTransformationCreate',
            'stored_lot.check'           => 'storedLotCheck',
            'slaughter.execute'       => 'slaughterExecute',
            'slaughter.close'         => 'slaughterClose',
            'slaughter.cutting'       => 'slaughterCutting',
            'mill_production.complete' => 'millProductionComplete',
            // Cœur sanitaire HACCP (spec Transformation — E1/E3/E4/E7).
            'slaughter_reception.create' => 'slaughterReceptionCreate',
            'ccp_record.create'          => 'ccpRecordCreate',
            'temperature_log.create'     => 'temperatureLogCreate',
            'cleaning_log.create'        => 'cleaningLogCreate',
            'byproduct.create'           => 'byproductCreate',
            // Tâches assignées : cocher « faite » depuis le terrain.
            'task.complete'              => 'taskComplete',
            // Verrouillage anti-doublon : prendre / libérer une tâche.
            'task.start'                 => 'taskStart',
            'task.release'               => 'taskRelease',
            // Tâche PERSONNELLE créée depuis le terrain (auto-assignée).
            'task.create'                => 'taskCreate',
        ];
    }

    /**
     * Traite UNE opération de la file d'outbox.
     *
     * @param  string $type     ex. « daily_check.create »
     * @param  array  $payload  données saisies hors-ligne
     * @return array{status: string}
     */
    public function handle(string $type, array $payload): array
    {
        $method = self::types()[$type] ?? null;

        if (! $method) {
            return ['status' => 'validation_failed', 'message' => __("Type d'opération inconnu : :type", ['type' => $type])];
        }

        return $this->{$method}($payload);
    }

    // ─────────────────────────────────────────────────────────────
    //  POINTAGE JOURNALIER — réutilise RecordDailyCheck (source unique :
    //  compensation aliment/fumier/eau + snapshot CMP + observer effectif).
    // ─────────────────────────────────────────────────────────────

    private function dailyCheckCreate(array $payload): array
    {
        if (Gate::denies('elevage.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'batch_id'             => ['required', 'integer', $this->farmScopedExists('batches')],
            'check_date'           => 'required|date',
            'mortality'            => 'nullable|integer|min:0',
            'avg_weight'           => 'nullable|numeric|min:0',
            'water_consumed'       => 'nullable|numeric|min:0',
            'feed_consumed'        => 'nullable|numeric|min:0',
            'feed_type'            => 'nullable|string|max:100',
            'humidity'             => 'nullable|numeric|min:0|max:100',
            'observations'         => 'nullable|string|max:1000',
            'qty_quarantine_in'    => 'nullable|integer|min:0',
            'qty_quarantine_out'   => 'nullable|integer|min:0',
            'qty_sorted_out'       => 'nullable|integer|min:0',
            // ── Parité avec le formulaire web (RecordDailyCheck sait les gérer) ──
            'health_status'        => 'nullable|in:Normal,Alerte,Critique',
            'temp_min'             => 'nullable|numeric|between:-10,50',
            'temp_max'             => 'nullable|numeric|between:-10,50|gte:temp_min',
            'mortality_infirmary'  => 'nullable|integer|min:0',
            'litter_changed'       => 'nullable|boolean',
            'manure_collected_kg'  => 'nullable|numeric|min:0|max:100000',
            'lame_count'           => 'nullable|integer|min:0|max:1000000',
            'pecking_injury_count' => 'nullable|integer|min:0|max:1000000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();
        $data['feed_type'] = $data['feed_type'] ?? '';
        // health_status est obligatoire côté web : on garantit une valeur par
        // défaut sûre si le terrain ne l'a pas renseigné (RAS).
        $data['health_status'] = $data['health_status'] ?? 'Normal';
        $data['check_date'] = Carbon::parse($data['check_date'])->startOfDay();

        return DB::transaction(function () use ($data) {
            // Idempotence : ce passage a déjà été appliqué (rejeu réseau).
            if (DailyCheck::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            // Conflit métier : un pointage existe déjà pour ce lot à cette date
            // (saisi en ligne entre-temps) → le terrain doit re-consulter.
            $dayExists = DailyCheck::where('batch_id', $data['batch_id'])
                ->where('check_date', $data['check_date'])
                ->exists();

            if ($dayExists) {
                return [
                    'status'  => 'conflict',
                    'message' => __('Un pointage existe déjà pour ce lot à cette date.'),
                ];
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $check = app(RecordDailyCheck::class)->execute($data);

            // uuid / drapeaux sync : volontairement HORS $fillable
            // (mass-assignment maîtrisé) → écriture explicite. NB : pas de
            // user_id ici — la colonne n'existe pas sur daily_checks (l'auteur
            // est tracé par l'audit trail) ; l'ancien contrôleur mort écrivait
            // cette colonne fantôme, silencieusement ignorée par $fillable.
            $check->forceFill([
                'uuid'         => $uuid,
                'is_synced'    => true,
                'last_sync_at' => now(),
            ])->save();

            Log::info("Sync: pointage réconcilié (uuid: {$uuid}, lot: {$check->batch_id}).");

            return ['status' => 'success', 'server_id' => $check->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  COLLECTE D'ŒUFS — cumul de passages, journal d'uuid appliqués.
    // ─────────────────────────────────────────────────────────────

    private function eggCollectionCreate(array $payload): array
    {
        if (Gate::denies('production.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'batch_id'             => ['required', 'integer', $this->farmScopedExists('batches')],
            'production_date'      => 'required|date|before_or_equal:today',
            'total_eggs_collected' => 'required|integer|min:0',
            'broken_eggs'          => 'nullable|integer|min:0',
            'small_eggs'           => 'nullable|integer|min:0',
            'observations'         => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $validated = $v->validated();

        return DB::transaction(function () use ($validated) {
            $existing = EggProduction::where('batch_id', $validated['batch_id'])
                ->where('production_date', $validated['production_date'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->is_graded) {
                    return [
                        'status'  => 'conflict',
                        'message' => __('Les œufs de ce jour ont déjà été triés et mis en stock.'),
                    ];
                }

                if (in_array($validated['uuid'], $existing->synced_uuids ?? [], true)) {
                    return ['status' => 'already_synced'];
                }
            }

            $production = app(RecordEggCollection::class)->execute([
                'batch_id'             => $validated['batch_id'],
                'production_date'      => $validated['production_date'],
                'total_eggs_collected' => $validated['total_eggs_collected'],
                'broken_eggs'          => $validated['broken_eggs'] ?? 0,
                'small_eggs'           => $validated['small_eggs'] ?? 0,
                'observations'         => $validated['observations'] ?? null,
            ]);

            $applied = $production->synced_uuids ?? [];
            $applied[] = $validated['uuid'];
            $production->update(['synced_uuids' => array_values(array_unique($applied))]);

            Log::info("Sync: collecte réconciliée (uuid: {$validated['uuid']}, lot: {$validated['batch_id']}).");

            return ['status' => 'success', 'server_id' => $production->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  MOUVEMENT DE STOCK — revérification de disponibilité au replay.
    // ─────────────────────────────────────────────────────────────

    private function stockMovementCreate(array $payload): array
    {
        if (Gate::denies('logistique.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'     => 'required|uuid',
            'stock_id' => ['required', 'integer', $this->farmScopedExists('stocks')],
            'type'     => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0.001',
            'notes'    => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $validated = $v->validated();

        return DB::transaction(function () use ($validated) {
            if (StockMovement::where('uuid', $validated['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $stock = Stock::lockForUpdate()->find($validated['stock_id']);

            if ($validated['type'] === 'out'
                && (float) $stock->current_quantity < (float) $validated['quantity']) {
                return [
                    'status'  => 'conflict',
                    'message' => __('Stock insuffisant pour :item (disponible : :qty :unit).', ['item' => $stock->item_name, 'qty' => $stock->current_quantity, 'unit' => $stock->unit]),
                ];
            }

            app(MoveStockAction::class)->execute(
                $validated['stock_id'],
                $validated['type'],
                (float) $validated['quantity'],
                $validated['notes'] ?? __('Mouvement saisi hors-ligne'),
                Auth::id(),
                $validated['uuid']
            );

            Log::info("Sync: mouvement stock réconcilié (uuid: {$validated['uuid']}, stock: {$validated['stock_id']}).");

            return ['status' => 'success'];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  VENTE RAPIDE — créée en BROUILLON (validation/déstockage en ligne).
    // ─────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────
    //  COUVOIR — MIRAGE (M5) : le mirage se fait EN SALLE d'incubation,
    //  œufs en main. Réutilise RecordMirage (taux de fertilité calculé,
    //  statut → mirage_fait). Idempotent par mirage_uuid.
    // ─────────────────────────────────────────────────────────────
    private function incubationMirage(array $payload): array
    {
        if (Gate::denies('production.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'           => 'required|uuid',
            'incubation_id'  => ['required', 'integer', $this->farmScopedExists('incubations')],
            'fertile_eggs'   => 'required|integer|min:0',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\Incubation::withoutGlobalScopes()->where('mirage_uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $incubation = \App\Models\Incubation::find($data['incubation_id']);
            if (! $incubation) {
                return ['status' => 'conflict', 'message' => __('Cycle d\'incubation introuvable dans cette ferme.')];
            }

            // Plafond physique (miroir du web) : on ne peut pas mirer plus
            // d'œufs fertiles qu'il n'y a d'œufs en machine.
            if ($data['fertile_eggs'] > (int) $incubation->eggs_count) {
                return $this->invalid(['fertile_eggs' => [
                    __('Œufs fertiles (:n) supérieurs aux œufs mis à couver (:total).', [
                        'n' => $data['fertile_eggs'], 'total' => $incubation->eggs_count,
                    ]),
                ]]);
            }

            try {
                $updated = app(\App\Actions\Incubation\RecordMirage::class)->execute($incubation, $data);
            } catch (\DomainException $e) {
                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            $updated->forceFill(['mirage_uuid' => $data['uuid']])->save();

            Log::info("Sync: mirage {$updated->code_incubation} — {$updated->fertility_rate}% de fertilité.");

            return ['status' => 'success', 'server_id' => $updated->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  COUVOIR — ÉCLOSION (M5) : comptage des poussins à la sortie.
    //  Réutilise RecordHatching (taux d'éclosabilité, cycle clos, incubateur
    //  en maintenance). Idempotent par hatch_uuid.
    // ─────────────────────────────────────────────────────────────
    private function incubationHatch(array $payload): array
    {
        if (Gate::denies('production.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'           => 'required|uuid',
            'incubation_id'  => ['required', 'integer', $this->farmScopedExists('incubations')],
            'hatched_chicks' => 'required|integer|min:0',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\Incubation::withoutGlobalScopes()->where('hatch_uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $incubation = \App\Models\Incubation::find($data['incubation_id']);
            if (! $incubation) {
                return ['status' => 'conflict', 'message' => __('Cycle d\'incubation introuvable dans cette ferme.')];
            }

            // Plafond physique : pas plus de poussins que d'œufs fertiles.
            if ($data['hatched_chicks'] > (int) $incubation->fertile_eggs) {
                return $this->invalid(['hatched_chicks' => [
                    __('Poussins éclos (:n) supérieurs aux œufs fertiles (:fertile) — faites d\'abord le mirage.', [
                        'n' => $data['hatched_chicks'], 'fertile' => $incubation->fertile_eggs,
                    ]),
                ]]);
            }

            $updated = app(\App\Actions\Incubation\RecordHatching::class)->execute($incubation, $data);

            // Compteurs de dispatch (colonnes optionnelles, comme le web).
            $counters = [];
            if (\Illuminate\Support\Facades\Schema::hasColumn('incubations', 'chicks_remaining')) {
                $counters['chicks_remaining'] = $updated->hatched_chicks;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('incubations', 'chicks_dispatched')) {
                $counters['chicks_dispatched'] = 0;
            }
            $updated->forceFill($counters + ['hatch_uuid' => $data['uuid']])->save();

            Log::info("Sync: éclosion {$updated->code_incubation} — {$updated->hatched_chicks} poussins ({$updated->hatchability_rate}%).");

            return ['status' => 'success', 'server_id' => $updated->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  TRAITE (M5) — collecte de lait matin/soir. total_liters est maintenu
    //  par le modèle ; unit_price est un snapshot du cours du jour.
    // ─────────────────────────────────────────────────────────────
    private function milkProductionCreate(array $payload): array
    {
        if (Gate::denies('production.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'batch_id'        => ['required', 'integer', $this->farmScopedExists('batches')],
            'production_date' => 'required|date|before_or_equal:today',
            'morning_liters'  => 'nullable|numeric|min:0',
            'evening_liters'  => 'nullable|numeric|min:0',
            'unit_price'      => 'nullable|numeric|min:0',
            'milking_females' => 'nullable|integer|min:0',
            'notes'           => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        if ((float) ($data['morning_liters'] ?? 0) + (float) ($data['evening_liters'] ?? 0) <= 0) {
            return $this->invalid(['morning_liters' => [__('Renseignez au moins une traite (matin ou soir).')]]);
        }

        return DB::transaction(function () use ($data) {
            if (\App\Models\MilkProduction::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $milk = \App\Models\MilkProduction::create($data + [
                'morning_liters' => $data['morning_liters'] ?? 0,
                'evening_liters' => $data['evening_liters'] ?? 0,
                'recorded_by'    => Auth::id(),
            ]);

            Log::info("Sync: traite lot #{$milk->batch_id} — {$milk->total_liters} L.");

            return ['status' => 'success', 'server_id' => $milk->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  RELEVÉ ÉNERGIE (M5) — compteur du groupe lu SUR PLACE. Réutilise
    //  RecordEnergyReading (carburant/coût estimés, compteur d'heures, alerte
    //  gasoil, bascule maintenance). Naturellement idempotent : un relevé par
    //  (source, jour) — un rejeu met à jour la même ligne.
    // ─────────────────────────────────────────────────────────────
    private function energyReadingCreate(array $payload): array
    {
        if (Gate::denies('ressources.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'energy_source_id'     => ['required', 'integer', $this->farmScopedExists('energy_sources')],
            'building_id'          => ['nullable', 'integer', $this->farmScopedExists('buildings')],
            'reading_date'         => 'required|date|before_or_equal:today',
            'hours_run'            => 'required|numeric|min:0|max:24',
            'fuel_consumed_liters' => 'nullable|numeric|min:0',
            'kwh_produced'         => 'nullable|numeric|min:0',
            'cost'                 => 'nullable|numeric|min:0',
            'outage_hours'         => 'nullable|numeric|min:0|max:24',
            'notes'                => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();
        unset($data['uuid']); // pas de colonne : l'unicité vient de (source, jour)

        $result = app(\App\Actions\Utility\RecordEnergyReading::class)->execute($data, Auth::id());

        Log::info("Sync: relevé énergie source #{$data['energy_source_id']} du {$data['reading_date']}.");

        return ['status' => 'success', 'server_id' => $result['reading']->id];
    }

    // ─────────────────────────────────────────────────────────────
    //  RELEVÉ EAU (M5) — consommation lue au compteur. Réutilise
    //  RecordWaterReading ; idempotent par (citerne, jour, is_refill=false),
    //  donc sans collision avec les ravitaillements (water_refill.create).
    // ─────────────────────────────────────────────────────────────
    private function waterReadingCreate(array $payload): array
    {
        if (Gate::denies('ressources.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                   => 'required|uuid',
            'water_source_id'        => ['required', 'integer', $this->farmScopedExists('water_sources')],
            'building_id'            => ['nullable', 'integer', $this->farmScopedExists('buildings')],
            'reading_date'           => 'required|date|before_or_equal:today',
            'volume_consumed_liters' => 'required|numeric|min:0',
            'quality_ph'             => 'nullable|numeric|min:0|max:14',
            'chlorine_level'         => 'nullable|numeric|min:0|max:10',
            'cost'                   => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();
        $uuid = $data['uuid'];
        unset($data['uuid']);

        $reading = app(\App\Actions\Utility\RecordWaterReading::class)->execute($data, Auth::id());
        $reading->forceFill(['uuid' => $uuid])->save();

        Log::info("Sync: relevé eau citerne #{$data['water_source_id']} du {$data['reading_date']}.");

        return ['status' => 'success', 'server_id' => $reading->id];
    }

    // ─────────────────────────────────────────────────────────────
    //  PRÉSENCE (M6) — grille de pointage du jour saisie AU RASSEMBLEMENT du
    //  matin, où qu'il ait lieu (pas au bureau). Une op = une journée entière,
    //  comme la grille web : c'est UN acte de pointage, pas N saisies.
    //  Réutilise RecordAttendance ; idempotent par (employé, jour) — donc un
    //  rejeu, ou une correction du soir, réécrit sans dupliquer.
    // ─────────────────────────────────────────────────────────────
    private function attendanceCreate(array $payload): array
    {
        if (Gate::denies('rh.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'attendance_date'      => 'required|date|before_or_equal:today',
            'rows'                 => 'required|array|min:1|max:300',
            'rows.*.employee_id'   => ['required', 'integer', $this->employeeExists()],
            'rows.*.status'        => ['required', Rule::in(array_keys(\App\Models\EmployeeAttendance::STATUSES))],
            'rows.*.check_in_time' => 'nullable|date_format:H:i',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        // Un employé ne peut pas avoir deux statuts le même jour : le doublon
        // vient d'une file corrompue, pas d'une intention — on refuse en bloc
        // plutôt que de laisser le dernier gagner silencieusement.
        $ids = array_column($data['rows'], 'employee_id');
        if (count($ids) !== count(array_unique($ids))) {
            return $this->invalid(['rows' => [__('Un employé apparaît deux fois dans la grille.')]]);
        }

        $result = app(\App\Actions\Hr\RecordAttendance::class)
            ->execute($data['attendance_date'], $data['rows'], Auth::id());

        Log::info("Sync: présence du {$data['attendance_date']} — {$result['saved']} employé(s) pointé(s).");

        return ['status' => 'success', 'saved' => $result['saved']];
    }

    // ─────────────────────────────────────────────────────────────
    //  LANCEMENT D'OP AU MOULIN (M4) — le meunier démarre la fabrication sur
    //  place. Rejoue la règle web d'OCCUPATION MACHINE (une machine ne traite
    //  qu'un OP ouvert à la fois) et fige la capacité au moment du lancement.
    //  L'OP naît « Planifié » ; sa clôture reste mill_production.complete.
    // ─────────────────────────────────────────────────────────────
    private function millProductionCreate(array $payload): array
    {
        if (Gate::denies('provenderie.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'          => 'required|uuid',
            'formula_id'     => ['required', 'integer', $this->farmScopedExists('formulas')],
            'machine_ids'    => 'required|array|min:1',
            'machine_ids.*'  => ['integer', $this->farmScopedExists('mill_machines')],
            'nb_bags'        => 'required|integer|min:1',
            'supervisor_id'  => ['required', 'integer', $this->employeeExists()],
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\MillProduction::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            // Occupation machine (miroir du web) : un OP est « ouvert » tant
            // qu'il n'est ni Terminé ni Annulé. Refus définitif → à corriger.
            $busy = DB::table('mill_production_machine')
                ->join('mill_productions', 'mill_productions.id', '=', 'mill_production_machine.mill_production_id')
                ->whereIn('mill_production_machine.mill_machine_id', $data['machine_ids'])
                ->whereNotIn('mill_productions.status', ['Terminé', 'Annulé'])
                ->pluck('mill_production_machine.mill_machine_id')
                ->unique();

            if ($busy->isNotEmpty()) {
                $names = \App\Models\MillMachine::whereIn('id', $busy)->pluck('name')->join(', ');

                return ['status' => 'conflict', 'message' => __(
                    'Machine(s) déjà engagée(s) sur un ordre en cours : :names. Clôturez l\'OP ouvert avant d\'en lancer un nouveau.',
                    ['names' => $names],
                )];
            }

            $totalWeight = \App\Services\UnitConverter::sacksToKg((float) $data['nb_bags']);

            $production = \App\Models\MillProduction::create([
                'uuid'              => $data['uuid'],
                'batch_number'      => \App\Services\DocumentNumberingService::generate('mill_production'),
                'formula_id'        => $data['formula_id'],
                'quantity_produced' => $totalWeight,
                'supervisor_id'     => $data['supervisor_id'],
                'operator_id'       => Auth::id(),
                'status'            => 'Planifié',
            ]);

            // Capacité FIGÉE au lancement (snapshot) — comme le web.
            $machines = [];
            foreach ($data['machine_ids'] as $machineId) {
                $machine = \App\Models\MillMachine::find($machineId);
                $machines[$machineId] = ['snapshot_capacity_per_hour' => $machine?->capacity_per_hour];
            }
            $production->machines()->attach($machines);

            Log::info("Sync: OP moulin {$production->batch_number} lancée — {$totalWeight} kg planifiés (uuid: {$data['uuid']}).");

            return ['status' => 'success', 'server_id' => $production->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  INVENTAIRE PHYSIQUE (M3) — le magasinier compte DEVANT le rayon.
    //  Réutilise CreateStockAdjustment : quantité recalée sous verrou, écart
    //  chiffré au CMP, mouvement « adjustment » + alerte anti-fraude.
    //  Un comptage SANS écart n'est pas une erreur : on l'absorbe (rien à
    //  ajuster) plutôt que d'envoyer l'opérateur au bac « À corriger ».
    // ─────────────────────────────────────────────────────────────
    private function inventoryCountCreate(array $payload): array
    {
        if (Gate::denies('logistique.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'             => 'required|uuid',
            'stock_id'         => ['required', 'integer', $this->farmScopedExists('stocks')],
            'counted_quantity' => 'required|numeric|min:0',
            'count_date'       => 'required|date|before_or_equal:today',
            'notes'            => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\StockAdjustment::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            try {
                $adjustment = app(\App\Actions\Stock\CreateStockAdjustment::class)->execute(
                    (int) $data['stock_id'],
                    (float) $data['counted_quantity'],
                    'inventaire',
                    $data['notes'] ?? null,
                    (int) Auth::id(),
                    $data['count_date'],
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                // « Aucun écart » : le comptage CONFIRME le stock — c'est un
                // succès métier, pas une saisie à corriger.
                Log::info("Sync: comptage sans écart sur stock #{$data['stock_id']} (uuid: {$data['uuid']}).");

                return ['status' => 'success', 'server_id' => null];
            }

            $adjustment->forceFill(['uuid' => $data['uuid']])->save();

            Log::info("Sync: inventaire {$adjustment->reference} — écart {$adjustment->delta} sur stock #{$data['stock_id']}.");

            return ['status' => 'success', 'server_id' => $adjustment->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  RÉCEPTION D'ALIMENT AU PORTAIL (M3) — le camion arrive à la ferme,
    //  pas au bureau. Réutilise CreateFeedPurchase : entrée de stock valorisée
    //  (CMP au coût réel), facture fournisseur et règlement/dette selon le
    //  mode de paiement. Idempotent par uuid.
    // ─────────────────────────────────────────────────────────────
    private function feedPurchaseCreate(array $payload): array
    {
        if (Gate::denies('provenderie.C') && Gate::denies('logistique.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'          => 'required|uuid',
            'batch_id'      => ['required', 'integer', $this->farmScopedExists('batches')],
            'purchase_date' => 'required|date|before_or_equal:today',
            'feed_type'     => 'required|string|max:255',
            'quantity'      => 'required|numeric|min:0.001',
            // Montant TOTAL payé (cohérent avec le web : unit_price = total).
            'unit_price'    => 'required|numeric|min:0',
            'unit'          => 'required|in:Sac,KG,Litre,Unité,Boite',
            'supplier'      => 'nullable|string|max:255',
            'payment_mode'  => 'nullable|in:comptant,credit',
            'metadata'      => 'nullable|array',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\FeedPurchase::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            try {
                $purchase = app(\App\Actions\FeedPurchase\CreateFeedPurchase::class)->execute($data);
            } catch (\Exception $e) {
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                    throw $e;
                }

                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            $purchase->forceFill(['uuid' => $data['uuid']])->save();

            Log::info("Sync: réception aliment {$purchase->feed_type} — {$data['quantity']} {$data['unit']} (uuid: {$data['uuid']}).");

            return ['status' => 'success', 'server_id' => $purchase->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  ENCAISSEMENT DE CRÉANCE (M2) — le livreur encaisse chez le client,
    //  hors réseau. Réutilise RecordPayment : reste dû relu SOUS VERROU
    //  (deux encaissements concurrents ne peuvent pas dépasser le dû),
    //  statut de vente et solde client recalculés, alerte propriétaire.
    //  Idempotent par uuid : un rejeu ne double JAMAIS l'encaissement.
    // ─────────────────────────────────────────────────────────────
    private function paymentCreate(array $payload): array
    {
        if (Gate::denies('commerce.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'         => 'required|uuid',
            'sale_id'      => ['required', 'integer', $this->farmScopedExists('sales')],
            'amount'       => 'required|numeric|min:1',
            'payment_date' => 'required|date|before_or_equal:today',
            'method'       => 'required|in:especes,orange_money,virement,cheque',
            'reference'    => 'nullable|string|max:100',
            'notes'        => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\Payment::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $sale = \App\Models\Sale::find($data['sale_id']);
            if (! $sale) {
                return ['status' => 'conflict', 'message' => __('Vente introuvable dans cette ferme.')];
            }

            try {
                $payment = app(\App\Actions\Sale\RecordPayment::class)->execute($sale, $data);
            } catch (\Exception $e) {
                // Règles métier (vente soldée entre-temps, montant > reste dû,
                // vente annulée…) : refus définitif → bac « À corriger ».
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                    throw $e;
                }

                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            $payment->forceFill(['uuid' => $data['uuid']])->save();

            Log::info("Sync: encaissement {$payment->amount} sur {$sale->reference} (uuid: {$data['uuid']}).");

            return ['status' => 'success', 'server_id' => $payment->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  RETOUR CLIENT (M2) — reprise de marchandise chez le client :
    //  remise en stock + avoir, via ProcessSaleReturn (source unique avec
    //  le web). Idempotent par uuid de retour.
    // ─────────────────────────────────────────────────────────────
    private function saleReturnCreate(array $payload): array
    {
        if (Gate::denies('commerce.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'sale_id'         => ['required', 'integer', $this->farmScopedExists('sales')],
            'reason'          => 'nullable|string|max:500',
            'refund_method'   => 'required|in:especes,orange_money,virement,cheque',
            'lines'           => 'required|array|min:1',
            'lines.*.sale_item_id' => 'required|integer',
            'lines.*.quantity'     => 'required|numeric|min:0.01',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\SaleReturn::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $sale = \App\Models\Sale::with('items')->find($data['sale_id']);
            if (! $sale) {
                return ['status' => 'conflict', 'message' => __('Vente introuvable dans cette ferme.')];
            }

            // Les lignes retournées doivent appartenir à CETTE vente (un client
            // hors-ligne peut poster un id de ligne obsolète après resync).
            $ownIds = $sale->items->pluck('id')->all();
            $lines = [];
            foreach ($data['lines'] as $line) {
                if (! in_array((int) $line['sale_item_id'], $ownIds, true)) {
                    return $this->invalid(['lines' => [
                        __('Ligne :id absente de la vente :ref.', ['id' => $line['sale_item_id'], 'ref' => $sale->reference]),
                    ]]);
                }
                $lines[(int) $line['sale_item_id']] = (float) $line['quantity'];
            }

            try {
                $return = app(\App\Actions\Sale\ProcessSaleReturn::class)
                    ->execute($sale, $lines, $data['reason'] ?? '', $data['refund_method']);
            } catch (\Throwable $e) {
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                    throw $e;
                }

                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            $return->forceFill(['uuid' => $data['uuid']])->save();

            Log::info("Sync: retour {$return->reference} sur {$sale->reference} — remboursement {$return->total_refund}.");

            return ['status' => 'success', 'server_id' => $return->id];
        });
    }

    private function saleCreate(array $payload): array
    {
        if (Gate::denies('commerce.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'client_id'            => ['required', 'integer', $this->farmScopedExists('clients')],
            'sale_date'            => 'required|date|before_or_equal:today',
            'type'                 => 'required|in:bon_livraison,facture',
            'tax_rate'             => 'nullable|numeric|min:0',
            'notes'                => 'nullable|string|max:1000',
            'immediate_payment'    => 'nullable|numeric|min:0',
            'payment_method'       => 'nullable|string|max:50',
            'items'                => 'required|array|min:1',
            'items.*.product_type' => 'required|string|max:40',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_id'   => 'nullable|integer',
            'items.*.batch_id'     => 'nullable|integer',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit'         => 'required|string|max:20',
            'items.*.unit_price'   => 'required|numeric|min:0',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $validated = $v->validated();

        return DB::transaction(function () use ($validated) {
            if (Sale::withoutGlobalScopes()->where('uuid', $validated['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $sale = app(CreateSale::class)->execute($validated);
            $sale->update(['is_synced' => true, 'last_sync_at' => now()]);

            Log::info("Sync: vente réconciliée (uuid: {$validated['uuid']}, ref: {$sale->reference}).");

            return ['status' => 'success', 'reference' => $sale->reference, 'server_id' => $sale->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  DÉPENSE — créée EN ATTENTE (validation P&L en ligne).
    // ─────────────────────────────────────────────────────────────

    private function expenseCreate(array $payload): array
    {
        if (Gate::denies('depenses.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'           => 'required|uuid',
            'category'       => 'required|string|max:50',
            'label'          => 'required|string|max:255',
            'amount'         => 'required|numeric|min:1',
            'expense_date'   => 'required|date|before_or_equal:today',
            'payment_method' => 'nullable|string|max:30',
            'batch_id'       => ['nullable', 'integer', $this->farmScopedExists('batches')],
            'supplier_name'  => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:2000',
            // Photo du reçu téléversée au préalable via POST /api/v1/photos.
            'photo_path'     => 'nullable|string|max:255',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $validated = $v->validated();

        return DB::transaction(function () use ($validated) {
            if (Expense::withoutGlobalScopes()->where('uuid', $validated['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $expense = app(CreateExpense::class)->execute(array_merge($validated, [
                'user_id'           => Auth::id(),
                'justificatif_path' => $validated['photo_path'] ?? null,
            ]));
            $expense->update(['is_synced' => true, 'last_sync_at' => now()]);

            Log::info("Sync: dépense réconciliée (uuid: {$validated['uuid']}, ref: {$expense->reference}).");

            return ['status' => 'success', 'reference' => $expense->reference, 'server_id' => $expense->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  LOT — upsert versionné, conflit Last-Write-Wins.
    //  Gates ALIGNÉS sur le module réel (elevage.*, plus admin.* — audit A2).
    // ─────────────────────────────────────────────────────────────

    private function batchUpsert(array $payload): array
    {
        $v = Validator::make($payload, [
            'uuid'                   => 'required|uuid',
            'code'                   => 'required|string|max:50',
            'type'                   => 'required|string',
            'building_id'            => ['required', 'integer', $this->farmScopedExists('buildings')],
            'initial_quantity'       => 'required|integer|min:1',
            'current_quantity'       => 'required|integer|min:0',
            'status'                 => 'nullable|string|in:Actif,Terminé',
            'arrival_date'           => 'required|date',
            'employee_id'            => ['nullable', 'integer', $this->employeeExists()],
            'provider_id'            => ['nullable', 'integer', $this->farmScopedExists('providers')],
            'qty_dead'               => 'nullable|integer|min:0',
            'arrival_mortality_rate' => 'nullable|numeric|min:0',
            'buy_price_per_unit'     => 'nullable|numeric|min:0',
            'updated_at'             => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $validated = $v->validated();

        $serverBatch = Batch::withoutGlobalScopes()->where('uuid', $validated['uuid'])->first();

        // Permission selon la nature réelle de l'opération (module Élevage).
        if (Gate::denies($serverBatch ? 'elevage.M' : 'elevage.C')) {
            return $this->denied();
        }

        // Conflit LWW : le serveur détient une version plus récente.
        if ($serverBatch && $serverBatch->updated_at->gt(Carbon::parse($validated['updated_at']))) {
            return [
                'status' => 'conflict',
                'data'   => $serverBatch->only([
                    'uuid', 'code', 'type', 'building_id',
                    'initial_quantity', 'current_quantity',
                    'status', 'arrival_date', 'updated_at',
                ]),
            ];
        }

        $price = (float) ($validated['buy_price_per_unit'] ?? 0);

        DB::transaction(function () use ($validated, $price) {
            Batch::withoutGlobalScopes()->updateOrCreate(
                ['uuid' => $validated['uuid']],
                [
                    'code'                   => $validated['code'],
                    'type'                   => $validated['type'],
                    'building_id'            => $validated['building_id'],
                    'initial_quantity'       => $validated['initial_quantity'],
                    'current_quantity'       => $validated['current_quantity'],
                    'qty_dead'               => $validated['qty_dead'] ?? 0,
                    'arrival_mortality_rate' => $validated['arrival_mortality_rate'] ?? 0,
                    'status'                 => $validated['status'] ?? 'Actif',
                    'arrival_date'           => $validated['arrival_date'],
                    'employee_id'            => $validated['employee_id'] ?? null,
                    'provider_id'            => $validated['provider_id'] ?? null,
                    'buy_price_per_unit'     => $price,
                    'total_acquisition_cost' => $price * $validated['initial_quantity'],
                    'is_synced'              => true,
                    'last_sync_at'           => now(),
                ]
            );
        });

        Log::info("Sync: lot réconcilié (uuid: {$validated['uuid']}, code: {$validated['code']}).");

        return ['status' => 'success'];
    }

    // ─── Helpers de statut ───

    /**
     * Déclaration d'incident sanitaire depuis le terrain (avec photo déjà
     * téléversée via POST /api/v1/photos → photo_path). L'alerte
     * multi-canaux part en best-effort, comme sur le web
     * (HealthIncidentController@store).
     */
    // ─────────────────────────────────────────────────────────────
    //  SOIN / VACCINATION (M1) — intervention sanitaire administrée au
    //  bâtiment. Le DÉLAI D'ATTENTE saisi ici verrouille l'abattage du lot
    //  jusqu'à son échéance (garde dans SlaughterService, levée automatique).
    //  Réutilise RecordHealthIntervention (source unique avec le web).
    // ─────────────────────────────────────────────────────────────
    private function healthCheckCreate(array $payload): array
    {
        if (Gate::denies('elevage.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                => 'required|uuid',
            'batch_id'            => ['required', 'integer', $this->farmScopedExists('batches')],
            'intervention_date'   => 'required|date|before_or_equal:today',
            'type'                => 'required|in:Vaccin,Traitement,Vitamine,Désinfection',
            'product_name'        => 'required|string|max:255',
            'dosage'              => 'nullable|string|max:100',
            'mode_administration' => 'required|string|max:100',
            // Délai d'attente de la notice (jours) — 0/absent = pas de délai.
            'withdrawal_days'     => 'nullable|integer|min:0|max:365',
            'batch_number'        => 'nullable|string|max:100',
            'expiry_date'         => 'nullable|date',
            'cost'                => 'nullable|numeric|min:0',
            'veterinary_name'     => 'nullable|string|max:255',
            'observations'        => 'nullable|string|max:2000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        // Garde-fou sanitaire (miroir du web) : produit périmé au jour de
        // l'intervention → refus définitif, pas une erreur rejouable.
        if (! empty($data['expiry_date'])
            && \Illuminate\Support\Carbon::parse($data['expiry_date'])->lt(\Illuminate\Support\Carbon::parse($data['intervention_date']))) {
            return $this->invalid(['expiry_date' => [
                __('Produit périmé au jour de l\'intervention — administration interdite.'),
            ]]);
        }

        return DB::transaction(function () use ($data) {
            if (\App\Models\HealthCheck::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $check = app(\App\Actions\Health\RecordHealthIntervention::class)->execute($data);
            $check->forceFill(['uuid' => $data['uuid']])->save();

            $note = $check->isUnderWithdrawal()
                ? " (délai d'attente jusqu'au {$check->withdrawal_until->toDateString()})"
                : '';
            Log::info("Sync: intervention sanitaire {$check->type} « {$check->product_name} » sur lot #{$check->batch_id}{$note}.");

            return ['status' => 'success', 'server_id' => $check->id];
        });
    }

    private function healthIncidentCreate(array $payload): array
    {
        if (Gate::denies('elevage.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'batch_id'        => ['required', 'integer', $this->farmScopedExists('batches')],
            'incident_date'   => 'required|date|before_or_equal:today',
            'mortality_count' => 'required|integer|min:0',
            'symptoms'        => 'required|string|max:2000',
            'severity'        => 'nullable|in:mineur,modere,critique',
            'photo_path'      => 'nullable|string|max:255',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            // Idempotence : rejeu réseau du même uuid.
            if (HealthIncident::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $batch = Batch::findOrFail($data['batch_id']);

            $incident = HealthIncident::create([
                'uuid'            => $data['uuid'],
                'building_id'     => $batch->building_id,
                'batch_id'        => $batch->id,
                'user_id'         => Auth::id(),
                'incident_date'   => $data['incident_date'],
                'mortality_count' => $data['mortality_count'],
                'symptoms'        => $data['symptoms'],
                'severity'        => $data['severity'] ?? HealthIncident::SEVERITY_MODERATE,
                'photo_path'      => $data['photo_path'] ?? null,
                'status'          => HealthIncident::STATUS_PENDING,
            ]);

            // Alerte (WhatsApp/SMS/mail selon préférences) — jamais bloquante.
            try {
                app(\App\Services\NotificationHub::class)->alertHealthIncident($incident);
            } catch (\Throwable $e) {
                Log::warning("Sync incident {$incident->id}: alerte non envoyée : {$e->getMessage()}");
            }

            return ['status' => 'success', 'server_id' => $incident->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  RÉCOLTE (cultures) — réutilise RecordHarvest (bascule du cycle en
    //  phase « recolte », intégration stock optionnelle au coût de production).
    // ─────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────
    //  POINTAGE DES SEMIS — déclaration d'un nouveau cycle de culture
    //  depuis le terrain (hors-ligne). Garde de surface (parcelle) au
    //  replay, idempotent par uuid. L'observer réconcilie la parcelle.
    // ─────────────────────────────────────────────────────────────
    private function cropCycleCreate(array $payload): array
    {
        if (Gate::denies('cultures.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                  => 'required|uuid',
            'plot_id'               => ['required', 'integer', $this->farmScopedExists('plots')],
            'crop_name'             => 'required|string|max:255',
            'variety'               => 'nullable|string|max:255',
            'area_used_ha'          => 'required|numeric|min:0.001',
            'planting_date'         => 'required|date|before_or_equal:today',
            'expected_harvest_date' => 'nullable|date|after_or_equal:planting_date',
            'seed_quantity'         => 'nullable|numeric|min:0',
            'seed_unit'             => 'nullable|string|max:20',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (CropCycle::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            /** @var \App\Models\Plot|null $plot */
            $plot = \App\Models\Plot::find($data['plot_id']);
            if (! $plot) {
                return ['status' => 'conflict', 'message' => __('Parcelle introuvable dans cette ferme.')];
            }

            // Garde de surface : la surface semée ne peut pas dépasser le
            // disponible sur la parcelle (cycles en cours). Refus définitif.
            $usedByOthers = (float) CropCycle::where('plot_id', $plot->id)
                ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
                ->sum('area_used_ha');
            $remaining = max(0.0, (float) $plot->area_ha - $usedByOthers);
            if ((float) $data['area_used_ha'] > $remaining + 0.0001) {
                return ['status' => 'conflict', 'message' => __(
                    'Surface semée (:req ha) dépasse le disponible sur la parcelle (:rem ha restant).',
                    ['req' => number_format((float) $data['area_used_ha'], 2), 'rem' => number_format($remaining, 2)]
                )];
            }

            $cycle = CropCycle::create(array_merge($data, [
                'status'       => CropCycle::STATUS_EN_COURS,
                'is_synced'    => true,
                'last_sync_at' => now(),
                'employee_id'  => Auth::user()?->employee?->id,
            ]));

            Log::info("Sync: semis réconcilié (uuid: {$data['uuid']}, parcelle: {$plot->code}, culture: {$cycle->crop_name}).");

            return ['status' => 'success', 'server_id' => $cycle->id];
        });
    }

    private function harvestCreate(array $payload): array
    {
        if (Gate::denies('cultures.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'crop_cycle_id'   => ['required', 'integer', $this->farmScopedExists('crop_cycles')],
            'harvest_date'    => 'required|date|before_or_equal:today',
            'quantity'        => 'required|numeric|min:0.001',
            'unit'            => 'nullable|string|max:20',
            'net_weight_kg'   => 'nullable|numeric|min:0',
            'loss_quantity'   => 'nullable|numeric|min:0',
            'quality'         => 'nullable|in:' . implode(',', Harvest::QUALITIES),
            // Destination (T1) : « vente » compte au revenu du cycle ; les
            // récoltes conservées n'encaissent rien et entrent en stock.
            'destination'     => 'nullable|in:' . implode(',', array_keys(Harvest::DESTINATIONS)),
            'unit_price'      => 'nullable|numeric|min:0',
            'sync_to_stock'   => 'nullable|boolean',
            'stock_item_name' => 'nullable|string|max:255',
            'notes'           => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (Harvest::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            // find() (et non exists:) sous FarmScope : un id d'une autre ferme
            // est un refus définitif, pas une erreur 500 rejouée.
            $cycle = CropCycle::find($data['crop_cycle_id']);
            if (! $cycle) {
                return ['status' => 'conflict', 'message' => __('Cycle de culture introuvable dans cette ferme.')];
            }

            if ($cycle->isArchived()) {
                return ['status' => 'conflict', 'message' => __('Le cycle :code est clos — récolte impossible.', ['code' => $cycle->code])];
            }

            // DÉLAI AVANT RÉCOLTE (résidus phytosanitaires) : après un
            // traitement, la production n'est pas récoltable avant l'échéance
            // de la notice. Levée AUTOMATIQUE à la date — refus définitif ici
            // (bac « À corriger »), pas une erreur rejouable.
            if ($blocking = $cycle->activePreharvestInterval(Carbon::parse($data['harvest_date']))) {
                return ['status' => 'conflict', 'message' => __(
                    "Le cycle :code est sous délai avant récolte jusqu'au :date (:n j restants) suite au traitement « :product » — récolte interdite (résidus).",
                    [
                        'code' => $cycle->code,
                        'date' => $blocking->harvest_allowed_from->format('d/m/Y'),
                        'n' => $blocking->preharvest_days_left,
                        'product' => $blocking->name,
                    ],
                )];
            }

            $uuid = $data['uuid'];
            unset($data['uuid'], $data['crop_cycle_id']);

            $harvest = app(RecordHarvest::class)->execute($cycle, $data);

            // L'uuid terrain remplace celui auto-généré (HasStandardUuid) :
            // c'est LUI la clé d'idempotence du rejeu réseau.
            $harvest->forceFill([
                'uuid'         => $uuid,
                'is_synced'    => true,
                'last_sync_at' => now(),
            ])->save();

            Log::info("Sync: récolte réconciliée (uuid: {$uuid}, cycle: {$cycle->code}).");

            return ['status' => 'success', 'server_id' => $harvest->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  TRANSFORMATION VÉGÉTALE (T1) — le séchoir est DEHORS, sans réseau : le
    //  lot se pèse et se saisit sur place, à la sortie des claies. Réutilise
    //  RecordCropTransformation : rendement, garde de rendement aberrant,
    //  déstockage strict de l'intrant et — surtout — valorisation du produit
    //  fini au COÛT DE REVIENT, pas au prix de vente espéré.
    // ─────────────────────────────────────────────────────────────
    private function cropTransformationCreate(array $payload): array
    {
        if (Gate::denies('cultures.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                => 'required|uuid',
            'harvest_id'          => ['nullable', 'integer', $this->farmScopedExists('harvests')],
            'crop_cycle_id'       => ['nullable', 'integer', $this->farmScopedExists('crop_cycles')],
            'crop_recipe_id'      => ['nullable', 'integer', $this->farmScopedExists('crop_recipes')],
            'input_product'       => 'required|string|max:255',
            'output_product'      => 'required|string|max:255',
            'transformation_type' => 'required|in:' . implode(',', array_keys(\App\Models\CropTransformation::TYPES)),
            'input_quantity'      => 'required|numeric|min:0.001',
            'input_unit'          => 'nullable|string|max:20',
            'output_quantity'     => 'required|numeric|min:0',
            'output_unit'         => 'nullable|string|max:20',
            'production_date'     => 'required|date|before_or_equal:today',
            'expiry_date'         => 'nullable|date|after_or_equal:production_date',
            'production_cost'     => 'nullable|numeric|min:0',
            'output_unit_price'   => 'nullable|numeric|min:0',
            'consumed_from_stock' => 'nullable|boolean',
            'input_stock_item'    => 'nullable|string|max:255',
            'synced_to_stock'     => 'nullable|boolean',
            'output_stock_item'   => 'nullable|string|max:255',
            'notes'               => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\CropTransformation::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            // Une récolte déjà transformée ne se re-transforme pas : ce serait
            // engager deux fois la même matière (et la compter deux fois en
            // coût). Refus DÉFINITIF, comme la re-sélection d'un ordre exécuté.
            if (! empty($data['harvest_id'])) {
                $harvest = \App\Models\Harvest::find($data['harvest_id']);
                if (! $harvest) {
                    return ['status' => 'conflict', 'message' => __('Récolte introuvable dans cette ferme.')];
                }
                if ($harvest->transformations()->exists()) {
                    return ['status' => 'conflict', 'message' => __(
                        'La récolte du :date a déjà été transformée — sa matière est engagée.',
                        ['date' => $harvest->harvest_date->format('d/m/Y')],
                    )];
                }
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $transformation = app(\App\Actions\Crop\RecordCropTransformation::class)->execute($data);

            $transformation->forceFill([
                'uuid'         => $uuid,
                'is_synced'    => true,
                'last_sync_at' => now(),
            ])->save();

            Log::info("Sync: transformation {$transformation->batch_number} — rendement {$transformation->yield_percent}%, coût de revient {$transformation->output_unit_cost}/u.");

            return ['status' => 'success', 'server_id' => $transformation->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  CONTRÔLE DE CONSERVATION (T2) — se fait AU MAGASIN, balance en main,
    //  souvent sans réseau. Réutilise RecordStoredLotCheck : la freinte est
    //  dérivée de la pesée, répercutée sur l'inventaire par un ajustement
    //  formel (motif « freinte »), et un constat grave exige une décision.
    // ─────────────────────────────────────────────────────────────
    private function storedLotCheck(array $payload): array
    {
        if (Gate::denies('logistique.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'             => 'required|uuid',
            'stored_lot_id'    => ['required', 'integer', $this->farmScopedExists('stored_lots')],
            // Même tolérance d'horloge que done_at : une dérive de téléphone ne
            // doit pas condamner un contrôle de lot au bac « À corriger ».
            'checked_at'       => ['nullable', 'date', 'before_or_equal:' . self::CLOCK_SKEW_TOLERANCE],
            'weighed_quantity' => 'nullable|numeric|min:0',
            'condition'        => ['required', Rule::in(array_keys(\App\Models\StoredLotCheck::CONDITIONS))],
            'action_taken'     => ['nullable', Rule::in(array_keys(\App\Models\StoredLotCheck::ACTIONS))],
            'market_price'     => 'nullable|numeric|min:0',
            'employee_id'      => ['nullable', 'integer', $this->employeeExists()],
            'photo_path'       => 'nullable|string|max:255',
            'notes'            => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\StoredLotCheck::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $lot = \App\Models\StoredLot::find($data['stored_lot_id']);
            if (! $lot) {
                return ['status' => 'conflict', 'message' => __('Lot de conservation introuvable dans cette ferme.')];
            }

            // Un lot clos (vendu, détruit) ne se contrôle plus : le terrain
            // travaillait sur une liste périmée. Refus DÉFINITIF.
            if (! $lot->is_open) {
                return ['status' => 'conflict', 'message' => __(
                    'Le lot « :label » est clos (:status) — aucun contrôle possible.',
                    ['label' => $lot->label, 'status' => $lot->status_label],
                )];
            }

            $uuid = $data['uuid'];
            unset($data['uuid'], $data['stored_lot_id']);

            $check = app(\App\Actions\Stock\RecordStoredLotCheck::class)->execute($lot, $data, Auth::id());

            $check->forceFill(['uuid' => $uuid])->save();

            Log::info("Sync: contrôle de conservation lot #{$lot->id} — freinte {$check->shrinkage_quantity} {$lot->unit}.");

            return ['status' => 'success', 'server_id' => $check->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  INTRANT (cultures) — coût total dérivé, entrée stock optionnelle.
    // ─────────────────────────────────────────────────────────────

    private function cropInputCreate(array $payload): array
    {
        if (Gate::denies('cultures.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'crop_cycle_id'   => ['required', 'integer', $this->farmScopedExists('crop_cycles')],
            'type'            => 'required|in:' . implode(',', array_keys(CropInput::TYPES)),
            'name'            => 'required|string|max:255',
            'input_date'      => 'required|date|before_or_equal:today',
            'quantity'        => 'nullable|numeric|min:0',
            'unit'            => 'nullable|string|max:20',
            'provider_id'     => ['nullable', 'integer', $this->farmScopedExists('providers')],
            'unit_cost'       => 'nullable|numeric|min:0',
            'total_cost'      => 'nullable|numeric|min:0',
            // Délai avant récolte (DAR) de la notice : bloque la récolte du
            // cycle jusqu'à l'échéance (0/absent = intrant sans délai).
            'preharvest_days' => 'nullable|integer|min:0|max:365',
            'synced_to_stock' => 'nullable|boolean',
            'stock_item_name' => 'nullable|string|max:255',
            'notes'           => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (CropInput::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $cycle = CropCycle::find($data['crop_cycle_id']);
            if (! $cycle) {
                return ['status' => 'conflict', 'message' => __('Cycle de culture introuvable dans cette ferme.')];
            }

            if ($cycle->isArchived()) {
                return ['status' => 'conflict', 'message' => __("Le cycle :code est clos — saisie d'intrant impossible.", ['code' => $cycle->code])];
            }

            $uuid = $data['uuid'];
            unset($data['uuid'], $data['crop_cycle_id']);

            $input = app(RecordCropInput::class)->execute($cycle, $data);

            $input->forceFill([
                'uuid'         => $uuid,
                'is_synced'    => true,
                'last_sync_at' => now(),
            ])->save();

            Log::info("Sync: intrant réconcilié (uuid: {$uuid}, cycle: {$cycle->code}).");

            return ['status' => 'success', 'server_id' => $input->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  ABATTAGE (abattoir) — exécution terrain d'un ordre planifié au bureau.
    //  Les gardes métier (quarantaine, effectif, carcasse ≤ vif, statut sous
    //  verrou) vivent dans SlaughterService — partagées avec le web.
    // ─────────────────────────────────────────────────────────────

    private function slaughterExecute(array $payload): array
    {
        if (Gate::denies('abattoir.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                    => 'required|uuid',
            'slaughter_order_id'      => ['required', 'integer', $this->farmScopedExists('slaughter_orders')],
            'execution_date'          => 'required|date|before_or_equal:today',
            'actual_quantity'         => 'required|integer|min:1',
            'total_live_weight_kg'    => 'required|numeric|min:0.1',
            'total_carcass_weight_kg' => 'required|numeric|min:0.1|lte:total_live_weight_kg',
            'condemned_count'         => 'nullable|integer|min:0',
            'condemned_reason'        => 'nullable|string|max:500',
            'inspector_notes'         => 'nullable|string|max:1000',
            'presentation'            => 'nullable|in:' . implode(',', array_keys(\App\Services\ButcheryNomenclature::presentations())),
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (SlaughterResult::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $order = SlaughterOrder::find($data['slaughter_order_id']);
            if (! $order) {
                return ['status' => 'conflict', 'message' => __("Ordre d'abattage introuvable dans cette ferme.")];
            }

            try {
                $result = app(SlaughterService::class)->executeSlaughter($order, $data);
            } catch (\Exception $e) {
                // SlaughterService signale ses règles métier par \Exception
                // (ordre déjà exécuté, quarantaine, effectif insuffisant…) :
                // refus définitif → bac « À corriger ». Les vraies pannes
                // (SQL…) restent des erreurs rejouables.
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                    throw $e;
                }

                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            $result->forceFill(['uuid' => $data['uuid']])->save();

            Log::info("Sync: abattage réconcilié (uuid: {$data['uuid']}, ordre: {$order->order_number}).");

            return ['status' => 'success', 'server_id' => $result->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  CLÔTURE DE CYCLE (abattoir) — checklist HACCP / déchets de fin de
    //  cycle. Exige les confirmations obligatoires ; idempotent (déjà clos).
    // ─────────────────────────────────────────────────────────────
    private function slaughterClose(array $payload): array
    {
        if (Gate::denies('abattoir.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'              => 'required|uuid',
            'slaughter_order_id' => ['required', 'integer', $this->farmScopedExists('slaughter_orders')],
            'waste_evacuated'   => 'accepted',
            'zone_cleaned'      => 'accepted',
            'marche_avant'      => 'accepted',
            'waste_destination' => 'nullable|string|max:255',
            'notes'             => 'nullable|string|max:1000',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();
        $order = SlaughterOrder::find($data['slaughter_order_id']);
        if (! $order) {
            return ['status' => 'conflict', 'message' => __("Ordre d'abattage introuvable dans cette ferme.")];
        }
        if ($order->isClosed()) {
            return ['status' => 'already_synced'];
        }

        try {
            app(\App\Actions\Slaughter\CloseSlaughterCycle::class)->execute($order, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['status' => 'conflict', 'message' => $e->getMessage()];
        }

        Log::info("Sync: cycle abattage clôturé (uuid: {$data['uuid']}, ordre: {$order->order_number}).");

        return ['status' => 'success', 'server_id' => $order->id];
    }

    // ─────────────────────────────────────────────────────────────
    //  DÉCOUPE (abattoir) — atelier de désassemblage depuis le mobile.
    //  Mêmes règles métier que le web (SlaughterService::executeCutting) :
    //  ordre terminé, conservation de matière, déchets pesés hors stock,
    //  répartition des coûts par valeur, routage transformation.
    //  Idempotent par uuid de session.
    // ─────────────────────────────────────────────────────────────
    private function slaughterCutting(array $payload): array
    {
        if (Gate::denies('abattoir.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                   => 'required|uuid',
            'slaughter_order_id'     => ['required', 'integer', $this->farmScopedExists('slaughter_orders')],
            'session_date'           => 'required|date|before_or_equal:today',
            'total_input_kg'         => 'required|numeric|min:0.1',
            'products'               => 'required|array|min:1',
            'products.*.type'        => 'required|string|max:40',
            'products.*.name'        => 'required|string|max:255',
            'products.*.kg'          => 'required|numeric|min:0',
            'products.*.pieces'      => 'nullable|integer|min:0',
            'products.*.price'       => 'nullable|numeric|min:0',
            'products.*.destination' => 'nullable|in:stock_frais,stock_congele,transformation,vente_directe,dechet',
            'products.*.calibre'     => 'nullable|string|max:40',
            'products.*.packaging'   => 'nullable|in:' . implode(',', \App\Models\CutProduct::PACKAGINGS),
            'products.*.pack_count'  => 'nullable|integer|min:0',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        // Conservation de matière côté validation (le service re-vérifie
        // contre la carcasse restante sous verrou).
        $totalOutput = collect($data['products'])->sum(fn ($p) => (float) ($p['kg'] ?? 0));
        if ($totalOutput > (float) $data['total_input_kg'] + 0.001) {
            return $this->invalid(['total_input_kg' => [
                __('Le total des morceaux (:out kg) dépasse le poids entré (:in kg).', [
                    'out' => number_format($totalOutput, 1), 'in' => number_format((float) $data['total_input_kg'], 1),
                ]),
            ]]);
        }

        return DB::transaction(function () use ($data) {
            if (\App\Models\CuttingSession::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $order = SlaughterOrder::find($data['slaughter_order_id']);
            if (! $order) {
                return ['status' => 'conflict', 'message' => __("Ordre d'abattage introuvable dans cette ferme.")];
            }

            // Codes admis = recette active OU nomenclature de l'espèce.
            $order->loadMissing('batch.species');
            $allowed = \App\Services\ButcheryNomenclature::effectiveCutCodesForSpecies($order->batch?->species);
            $badTypes = collect($data['products'])->pluck('type')->unique()->diff($allowed);
            if ($badTypes->isNotEmpty()) {
                return $this->invalid(['products' => [
                    __('Types de découpe inconnus pour cette espèce : :types', ['types' => $badTypes->implode(', ')]),
                ]]);
            }

            try {
                $session = app(SlaughterService::class)->executeCutting($order, $data);
            } catch (\Exception $e) {
                // Règles métier (ordre non terminé, carcasse insuffisante…) :
                // refus définitif → bac « À corriger ». Les vraies pannes
                // (SQL…) restent des erreurs rejouables.
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                    throw $e;
                }

                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            Log::info("Sync: découpe réconciliée (uuid: {$data['uuid']}, ordre: {$order->order_number}, {$data['total_input_kg']} kg).");

            return ['status' => 'success', 'server_id' => $session->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  CLÔTURE D'OP (provenderie) — consomme les MP et crédite le silo
    //  d'aliment fini (CompleteMillProduction, partagé avec le web).
    //  L'op ne crée aucune ligne : l'uuid de clôture est mémorisé sur l'OP
    //  (completion_uuid) pour distinguer rejeu et clôture concurrente.
    // ─────────────────────────────────────────────────────────────

    private function millProductionComplete(array $payload): array
    {
        if (Gate::denies('provenderie.M')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'               => 'required|uuid',
            'mill_production_id' => ['required', 'integer', $this->farmScopedExists('mill_productions')],
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            $production = MillProduction::lockForUpdate()->find($data['mill_production_id']);
            if (! $production) {
                return ['status' => 'conflict', 'message' => __('Ordre de production introuvable dans cette ferme.')];
            }

            if ($production->status === 'Terminé') {
                return $production->completion_uuid === $data['uuid']
                    ? ['status' => 'already_synced']
                    : ['status' => 'conflict', 'message' => __("L'OP #:op a déjà été clôturée (en ligne ou par un autre appareil).", ['op' => $production->batch_number])];
            }

            if ($production->status === 'Annulé') {
                return ['status' => 'conflict', 'message' => __("L'OP #:op a été annulée.", ['op' => $production->batch_number])];
            }

            try {
                app(CompleteMillProduction::class)->execute($production);
            } catch (\DomainException|\RuntimeException $e) {
                // Règles métier de la clôture (stock MP insuffisant, machine en
                // panne, MP sans prix…) : refus définitif, l'utilisateur arbitre.
                if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException) {
                    throw $e;
                }

                return ['status' => 'conflict', 'message' => $e->getMessage()];
            }

            $production->forceFill(['completion_uuid' => $data['uuid']])->save();

            Log::info("Sync: OP clôturé (uuid: {$data['uuid']}, OP: {$production->batch_number}).");

            return ['status' => 'success', 'server_id' => $production->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  RÉCEPTION DU VIF (CCP 1) — contrôle ante-mortem, immuable à la
    //  création. Décision ≠ accepté → motif obligatoire + alerte qualité.
    // ─────────────────────────────────────────────────────────────

    private function slaughterReceptionCreate(array $payload): array
    {
        if (Gate::denies('abattoir.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'provider_id'          => ['required', 'integer', $this->farmScopedExists('providers')],
            'reception_date'       => 'required|date|before_or_equal:today',
            'announced_quantity'   => 'nullable|integer|min:0',
            'received_quantity'    => 'required|integer|min:1',
            'rejected_quantity'    => 'nullable|integer|min:0|lte:received_quantity',
            'total_live_weight_kg' => 'required|numeric|min:0.1',
            'sanitary_state'       => 'required|in:' . implode(',', \App\Models\SlaughterReception::SANITARY_STATES),
            'fasting_respected'    => 'required|in:' . implode(',', \App\Models\SlaughterReception::FASTING),
            'decision'             => 'required|in:' . implode(',', \App\Models\SlaughterReception::DECISIONS),
            'decision_reason'      => 'required_unless:decision,accepte|nullable|string|max:1000',
            'origin'               => 'nullable|in:' . implode(',', \App\Models\SlaughterReception::ORIGINS),
            'purchase_basis'       => 'nullable|in:' . implode(',', array_keys(\App\Models\SlaughterReception::PURCHASE_BASES)),
            'purchase_unit_price'  => 'nullable|numeric|min:0',
            'photo_path'           => 'nullable|string|max:255',
            'releve_at'            => 'nullable|date',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\SlaughterReception::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $reception = app(\App\Actions\Slaughter\RecordSlaughterReception::class)->execute(array_merge($data, [
                'controller_id'  => Auth::id(),
                'doc_photo_path' => $data['photo_path'] ?? null,
            ]));

            $reception->forceFill([
                'uuid'         => $uuid,
                'synced_at'    => now(),
                'is_synced'    => true,
                'last_sync_at' => now(),
            ])->save();

            Log::info("Sync: réception vif réconciliée (uuid: {$uuid}, décision: {$reception->decision}).");

            return ['status' => 'success', 'server_id' => $reception->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  RELEVÉ CCP — conformité calculée SERVEUR (seuils Réglages) ;
    //  non conforme + ordre → blocage automatique (RG-02). INSERT-ONLY.
    // ─────────────────────────────────────────────────────────────

    private function ccpRecordCreate(array $payload): array
    {
        if (Gate::denies('abattoir.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'               => 'required|uuid',
            'ccp'                => 'required|in:' . implode(',', \App\Models\CcpRecord::CCPS),
            'slaughter_order_id' => ['nullable', 'integer', $this->farmScopedExists('slaughter_orders')],
            'equipment_ref'      => 'nullable|string|max:50',
            'mesures'            => 'required|array|min:1',
            'conforme'           => 'nullable|boolean',
            'corrective_action'  => 'nullable|string|max:2000',
            'releve_at'          => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\CcpRecord::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            if (! empty($data['slaughter_order_id'])
                && ! SlaughterOrder::whereKey($data['slaughter_order_id'])->exists()) {
                return ['status' => 'conflict', 'message' => __("Ordre d'abattage introuvable dans cette ferme.")];
            }

            $action = app(\App\Actions\Slaughter\RecordCcp::class);

            // Non conforme (évaluation SERVEUR) sans action corrective :
            // refus définitif AVANT toute écriture — le plan HACCP exige
            // l'action en face du constat.
            $conforme = $action->evaluate($data['ccp'], $data['mesures'], $data['conforme'] ?? null);
            if (! $conforme && blank($data['corrective_action'] ?? null)) {
                return [
                    'status'  => 'conflict',
                    'message' => __('Une action corrective est obligatoire pour un CCP non conforme.'),
                ];
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $record = $action->execute(array_merge($data, [
                'operator_id' => Auth::id(),
            ]));

            $record->forceFill(['uuid' => $uuid])->save();

            Log::info("Sync: relevé CCP réconcilié (uuid: {$uuid}, {$record->ccp}, conforme: " . ($record->conforme ? 'oui' : 'NON') . ').');

            return ['status' => 'success', 'server_id' => $record->id, 'conforme' => $record->conforme];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  REGISTRE DES TEMPÉRATURES — conformité serveur, alerte immédiate.
    // ─────────────────────────────────────────────────────────────

    private function temperatureLogCreate(array $payload): array
    {
        if (Gate::denies('abattoir.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'              => 'required|uuid',
            'point'             => 'required|in:' . implode(',', array_keys(\App\Models\TemperatureLog::POINTS)),
            'equipment_ref'     => 'nullable|string|max:50',
            'temperature'       => 'required|numeric|min:-60|max:120',
            'corrective_action' => 'nullable|string|max:2000',
            'releve_at'         => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\TemperatureLog::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $log = app(\App\Actions\Slaughter\RecordTemperatureLog::class)->execute(array_merge($data, [
                'operator_id' => Auth::id(),
            ]));

            $log->forceFill(['uuid' => $uuid])->save();

            Log::info("Sync: relevé température réconcilié (uuid: {$uuid}, {$log->point}: {$log->temperature}°C).");

            return ['status' => 'success', 'server_id' => $log->id, 'conforme' => $log->conforme];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  REGISTRE NETTOYAGE / DÉSINFECTION — trace simple, insert-only.
    // ─────────────────────────────────────────────────────────────

    private function cleaningLogCreate(array $payload): array
    {
        if (Gate::denies('abattoir.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'         => 'required|uuid',
            'zone'         => 'required|string|max:100',
            'product_used' => 'required|string|max:100',
            'dosage'       => 'nullable|string|max:50',
            'notes'        => 'nullable|string|max:1000',
            'photo_path'   => 'nullable|string|max:255',
            'done_at'      => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\CleaningLog::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $log = \App\Models\CleaningLog::create(array_merge($data, [
                'operator_id' => Auth::id(),
                'synced_at'   => now(),
            ]));

            $log->forceFill(['uuid' => $uuid])->save();

            Log::info("Sync: nettoyage réconcilié (uuid: {$uuid}, zone: {$log->zone}).");

            return ['status' => 'success', 'server_id' => $log->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  SOUS-PRODUITS (E9) — sang, plumes, viscères : volume + destination.
    // ─────────────────────────────────────────────────────────────

    private function byproductCreate(array $payload): array
    {
        if (Gate::denies('abattoir.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'               => 'required|uuid',
            'slaughter_order_id' => ['nullable', 'integer', $this->farmScopedExists('slaughter_orders')],
            'type'               => 'required|in:' . implode(',', array_keys(\App\Models\SlaughterByproduct::TYPES)),
            'quantity_kg'        => 'required|numeric|min:0.01',
            'destination'        => 'required|in:' . implode(',', array_keys(\App\Models\SlaughterByproduct::DESTINATIONS)),
            'notes'              => 'nullable|string|max:1000',
            'collected_at'       => 'required|date',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\SlaughterByproduct::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $uuid = $data['uuid'];
            unset($data['uuid']);

            $byproduct = \App\Models\SlaughterByproduct::create(array_merge($data, [
                'operator_id' => Auth::id(),
                'synced_at'   => now(),
            ]));

            $byproduct->forceFill(['uuid' => $uuid])->save();

            Log::info("Sync: sous-produit réconcilié (uuid: {$uuid}, {$byproduct->type}: {$byproduct->quantity_kg} kg).");

            return ['status' => 'success', 'server_id' => $byproduct->id];
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  TÂCHE ASSIGNÉE — cocher « faite » depuis le terrain. Autorisé pour
    //  SA propre tâche (employé rattaché) ou pour un superviseur (rh.M).
    //  Idempotent : re-cocher une tâche déjà faite = already_synced.
    // ─────────────────────────────────────────────────────────────
    private function taskComplete(array $payload): array
    {
        $v = Validator::make($payload, [
            'uuid'        => 'required|uuid',
            'task_id'     => ['required', 'integer', $this->farmScopedExists('task_assignments')],
            'notes'       => 'nullable|string|max:500',
            'photo_path'  => 'nullable|string|max:255', // substitué par le pipeline photo
            'proof_value' => 'nullable|numeric|min:0',
            // HORODATAGE DÉCLARÉ (S2) — l'instant réel de l'acte, saisi au champ.
            // Sans lui, completed_at valait l'heure d'ARRIVÉE au serveur : une
            // tâche faite lundi et poussée mercredi (site sans couverture réseau)
            // comptait comme faite mercredi, donc « en retard ». L'indicateur de
            // ponctualité punissait alors un problème de réseau, pas un
            // manquement — et un indicateur injuste n'est jamais utilisé.
            // Borné : ni dans le futur, ni au-delà de 30 jours en arrière.
            //
            // TOLÉRANCE D'HORLOGE. `before_or_equal:now` condamnait une saisie dès
            // que le téléphone avait quelques secondes d'avance sur le serveur —
            // ce qui est la NORME sur les appareils du terrain, dont l'horloge
            // dérive. Et un `validation_failed` est terminal : la tâche partait au
            // bac « À corriger » et le technicien devait tout ressaisir, pour une
            // dérive d'horloge de trente secondes.
            //
            // On accepte donc une avance BORNÉE (cf. CLOCK_SKEW_TOLERANCE) et on
            // ramène l'horodatage à l'instant serveur au moment d'écrire. Au-delà,
            // on refuse toujours : dater une tâche la semaine prochaine n'est pas
            // une dérive d'horloge.
            'done_at'     => ['nullable', 'date', 'before_or_equal:' . self::CLOCK_SKEW_TOLERANCE, 'after:-30 days'],
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        /** @var \App\Models\TaskAssignment $task */
        $task = \App\Models\TaskAssignment::find($data['task_id']);

        $myEmployeeId = Auth::user()?->employee?->id;
        $isOwner = $task->employee_id !== null && $task->employee_id === $myEmployeeId;

        if (! $isOwner && Gate::denies('rh.M')) {
            return $this->denied();
        }

        if ($task->status === 'fait') {
            return ['status' => 'already_synced'];
        }

        // Anti-doublon : une tâche PRISE (en cours, non expirée) par un AUTRE
        // que celui qui clôture ne peut pas être terminée par lui — sinon le
        // verrou serait contournable en sautant l'étape « prendre ». La prise
        // du clôtureur (ou une prise expirée) ne bloque pas.
        if ($task->isClaimedByOther(Auth::id())) {
            return ['status' => 'conflict', 'message' => __('Tâche en cours par :name — impossible de la valider à sa place.', [
                'name' => $task->claimant?->name ?? '—',
            ])];
        }

        // Preuve d'exécution — VÉRIFICATION AUTORITAIRE serveur : une preuve
        // manquante est un refus NON REJOUABLE (conflict → bac « À corriger »),
        // pas un no-op. Empêche de clôturer sans la photo/valeur exigée, même si
        // un client altéré tentait de passer outre.
        if ($task->proof_type === 'photo' && empty($data['photo_path'])) {
            return ['status' => 'conflict', 'message' => __('Cette tâche exige une photo pour être validée.')];
        }
        if ($task->proof_type === 'valeur' && ($data['proof_value'] ?? null) === null) {
            return ['status' => 'conflict', 'message' => __('Cette tâche exige une valeur chiffrée pour être validée.')];
        }

        $declared = ! empty($data['done_at']) ? Carbon::parse($data['done_at']) : null;

        // L'avance d'horloge tolérée est RAMENÉE à l'instant serveur : on ne veut
        // pas d'un acte daté dans le futur au registre, mais on ne rejette pas la
        // saisie pour autant.
        if ($declared && $declared->isFuture()) {
            $declared = now();
        }

        $task->update([
            'status'           => 'fait',
            'completed_at'     => $declared ?? now(),
            'completed_by'     => Auth::id(),
            'completion_notes' => $data['notes'] ?? null,
            'proof_photo_path' => $data['photo_path'] ?? null,
            'proof_value'      => $data['proof_value'] ?? null,
        ]);

        // Boucle de retour itinéraire technique (S1) : une étape phénologique
        // cochée au terrain est validée dans le registre du cycle — même point
        // de vérité que le web.
        $task->recordProtocolCompletion(Auth::id(), $data['notes'] ?? null);

        // Audit RH : qui a terminé, avec quelle preuve. On journalise AUSSI le
        // décalage entre l'acte déclaré et son arrivée serveur : c'est la trace
        // qui distingue « fait hors réseau » de « saisi en retard ».
        $task->logLifecycle('completed', array_filter([
            'statut'      => 'fait',
            'preuve'      => $task->proof_type !== 'aucune' ? $task->proof_type : null,
            'valeur'      => $data['proof_value'] ?? null,
            'photo'       => $data['photo_path'] ?? null,
            'fait_le'     => $declared?->toDateTimeString(),
            'poussé_le'   => $declared ? now()->toDateTimeString() : null,
        ], fn ($v) => $v !== null));

        Log::info("Sync: tâche #{$task->id} terminée (uuid: {$data['uuid']}, preuve: {$task->proof_type}).");

        return ['status' => 'success', 'server_id' => $task->id];
    }

    // ─────────────────────────────────────────────────────────────
    //  VERROU DE TÂCHE (anti-doublon) — prise / libération.
    //  Modèle optimiste : la prise pose en_cours + started_at + claimed_by ;
    //  le conflit « déjà prise » se résout à la synchro ; une prise expirée
    //  (timeout) est reprenable.
    // ─────────────────────────────────────────────────────────────
    private function taskStart(array $payload): array
    {
        $v = Validator::make($payload, [
            'uuid'    => 'required|uuid',
            'task_id' => ['required', 'integer', $this->farmScopedExists('task_assignments')],
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        return DB::transaction(function () use ($v) {
            /** @var \App\Models\TaskAssignment $task */
            $task = \App\Models\TaskAssignment::whereKey($v->validated()['task_id'])->lockForUpdate()->first();

            $myEmployeeId = Auth::user()?->employee?->id;
            $isOwner = $task->employee_id !== null && $task->employee_id === $myEmployeeId;
            // Tâche de POOL (libre-service) : tout ouvrier de la ferme (ayant une
            // fiche employé) peut la prendre. Sinon, seul le titulaire ou rh.M.
            $canPool = $task->is_pool && $myEmployeeId !== null;
            if (! $isOwner && ! $canPool && Gate::denies('rh.M')) {
                return $this->denied();
            }

            if ($task->status === 'fait') {
                return ['status' => 'conflict', 'message' => __('Tâche déjà terminée.')];
            }

            // Déjà prise par MOI (rejeu réseau) → succès idempotent.
            if ($task->status === 'en_cours' && $task->claimed_by === Auth::id() && ! $task->isClaimStale()) {
                return ['status' => 'already_synced'];
            }

            // Prise ACTIVE par un autre → refus (le terrain verra « en cours par X »).
            if ($task->isClaimedByOther(Auth::id())) {
                return ['status' => 'conflict', 'message' => __('Tâche déjà prise par :name.', [
                    'name' => $task->claimant?->name ?? '—',
                ])];
            }

            // Libre (ou prise expirée) → on la prend. Une tâche de pool s'ATTRIBUE
            // au preneur (elle quitte le libre-service tant qu'il la détient).
            $task->update([
                'status'      => 'en_cours',
                'started_at'  => now(),
                'claimed_by'  => Auth::id(),
                'employee_id' => ($task->is_pool && $myEmployeeId) ? $myEmployeeId : $task->employee_id,
            ]);

            $task->logLifecycle('claimed', ['statut' => 'en_cours', 'prise_a' => now()->toDateTimeString()]);
            Log::info("Sync: tâche #{$task->id} prise par user " . Auth::id() . '.');

            return ['status' => 'success', 'server_id' => $task->id];
        });
    }

    private function taskRelease(array $payload): array
    {
        $v = Validator::make($payload, [
            'uuid'    => 'required|uuid',
            'task_id' => ['required', 'integer', $this->farmScopedExists('task_assignments')],
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        /** @var \App\Models\TaskAssignment $task */
        $task = \App\Models\TaskAssignment::find($v->validated()['task_id']);

        if ($task->status === 'fait') {
            return ['status' => 'already_synced']; // rien à libérer, déjà close
        }

        // On ne libère que SA propre prise (ou un superviseur rh.M). Une prise
        // d'un autre n'est pas libérable ici (elle expirera au timeout).
        $isMine = $task->claimed_by === Auth::id();
        if (! $isMine && Gate::denies('rh.M')) {
            return $this->denied();
        }

        // Une tâche de pool retourne au LIBRE-SERVICE (sans titulaire) ; une
        // tâche assignée reste attribuée à son employé.
        $task->update([
            'status'      => 'a_faire',
            'started_at'  => null,
            'claimed_by'  => null,
            'employee_id' => $task->is_pool ? null : $task->employee_id,
        ]);

        $task->logLifecycle('released', ['statut' => 'a_faire']);
        Log::info("Sync: tâche #{$task->id} libérée par user " . Auth::id() . '.');

        return ['status' => 'success', 'server_id' => $task->id];
    }

    /**
     * Règle « exists » bornée à la ferme courante (étanchéité multi-fermes).
     *
     * La validation Laravel `exists:` ignore les global scopes Eloquent : sans
     * borne explicite, un appareil pourrait référencer l'id d'une entité d'une
     * AUTRE ferme (FK croisée). On restreint donc à session('current_farm_id')
     * — première ligne, en complément du findOrFail scopé en aval.
     */
    /**
     * Ravitaillement d'une citerne saisi hors-ligne : appoint d'eau
     * INDÉPENDANT du relevé (consommation 0). Enregistre l'événement + ajoute
     * le volume au niveau courant (plafonné). Miroir de
     * UtilityController::refillWaterSource, idempotent par uuid.
     */
    private function waterRefillCreate(array $payload): array
    {
        if (Gate::denies('ressources.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                => 'required|uuid',
            'water_source_id'     => ['required', 'integer', $this->farmScopedExists('water_sources')],
            'volume_added_liters' => 'required|numeric|min:1',
            'refill_date'         => 'required|date|before_or_equal:today',
            'cost'                => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data) {
            if (\App\Models\WaterReading::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $source = \App\Models\WaterSource::lockForUpdate()->find($data['water_source_id']);

            // Anti-débordement : une citerne ne se remplit pas au-delà de sa
            // capacité → conflict (bac « À corriger »), message explicite.
            if ($source->type === 'citerne' && $source->capacity_liters) {
                $remaining = (float) $source->capacity_liters - (float) $source->current_level_liters;
                if ((float) $data['volume_added_liters'] > $remaining + 0.01) {
                    return [
                        'status'  => 'conflict',
                        'message' => __('Ravitaillement supérieur à la capacité : il reste :qty L dans :name.', [
                            'qty'  => number_format(max(0, $remaining), 0, ',', ' '),
                            'name' => $source->name,
                        ]),
                    ];
                }
            }

            \App\Models\WaterReading::create([
                'uuid'                   => $data['uuid'],
                'water_source_id'        => $source->id,
                'reading_date'           => $data['refill_date'],
                'user_id'                => Auth::id(),
                'volume_consumed_liters' => 0,
                'volume_added_liters'    => $data['volume_added_liters'],
                'is_refill'              => true,
                'cost'                   => $data['cost'] ?? 0,
                'notes'                  => $data['notes'] ?? null,
            ]);

            if ($source->type === 'citerne' && $source->capacity_liters) {
                $newLevel = min((float) $source->capacity_liters,
                    (float) $source->current_level_liters + (float) $data['volume_added_liters']);
                $source->update([
                    'current_level_liters'  => $newLevel,
                    'current_level_percent' => min(100, $newLevel / (float) $source->capacity_liters * 100),
                ]);
            }

            Log::info("Sync: ravitaillement citerne réconcilié (uuid: {$data['uuid']}, source: {$source->id}).");

            return ['status' => 'success'];
        });
    }

    private function farmScopedExists(string $table): \Illuminate\Validation\Rules\Exists
    {
        $rule = Rule::exists($table, 'id');
        $farmId = session('current_farm_id');

        if ($farmId) {
            $rule->where('farm_id', $farmId);
        }

        return $rule;
    }

    /**
     * EMPLOYÉ VALIDE — la MÊME règle que celle qui l'a descendu au téléphone.
     *
     * Signalé depuis le terrain : « rows.0.employee_id sélectionné est invalide »
     * sur les trois lignes d'un pointage de présence, saisie condamnée au bac
     * « À corriger ».
     *
     * Le miroir mobile est alimenté par `Employee::scopeActiveForSync`, qui appelle
     * `assignableInCurrentFarm()` : elle inclut délibérément les agents PRÊTÉS —
     * ceux dont le COMPTE a reçu l'accès à cette ferme alors que leur dossier
     * reste rattaché à leur site d'origine. La validation du push, elle, exigeait
     * `employees.farm_id = ferme courante`.
     *
     * Le téléphone PROPOSAIT donc des employés que le serveur REFUSAIT. Le
     * technicien les cochait, et sa feuille de présence entière était rejetée.
     *
     * C'est le même défaut que « listé mais pas ouvrable » corrigé côté web : une
     * règle de visibilité, deux implémentations. Cette fois elles se
     * contredisaient au point de rendre la fonction inutilisable.
     */
    private function employeeExists(): \Illuminate\Validation\Rules\Exists
    {
        $rule = Rule::exists('employees', 'id');
        $farmId = session('current_farm_id');

        if (! $farmId) {
            return $rule;
        }

        return $rule->where(function ($query) use ($farmId) {
            $query->where(function ($sub) use ($farmId) {
                $sub->where('farm_id', $farmId)
                    ->orWhereIn('user_id', function ($accounts) use ($farmId) {
                        $accounts->select('user_id')->from('farm_user')->where('farm_id', $farmId);
                    });
            })
            // Un dossier ARCHIVÉ ne doit pas revenir par cette porte.
            ->whereNull('deleted_at');
        });
    }

    /**
     * Création d'une tâche PERSONNELLE depuis le terrain : auto-assignée à
     * l'employé connecté (comme le fait le web « Mes tâches »). L'affectation à
     * autrui reste une opération web (rh.C) — ici, aucun droit RH requis, un
     * agent gère sa propre liste de tâches, à l'image de task.complete.
     */
    private function taskCreate(array $payload): array
    {
        $employeeId = Auth::user()?->employee?->id;
        if (! $employeeId) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'           => 'required|uuid',
            'title'          => 'required|string|max:255',
            'category'       => 'required|string|max:50',
            'scheduled_date' => 'required|date',
            'priority'       => 'nullable|in:basse,normale,haute,critique',
            'description'    => 'nullable|string|max:500',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();

        return DB::transaction(function () use ($data, $employeeId) {
            if (\App\Models\TaskAssignment::withoutGlobalScopes()->where('uuid', $data['uuid'])->exists()) {
                return ['status' => 'already_synced'];
            }

            $task = \App\Models\TaskAssignment::create([
                'uuid'              => $data['uuid'],
                'title'             => $data['title'],
                'category'          => $data['category'],
                'employee_id'       => $employeeId,
                'scheduled_date'    => $data['scheduled_date'],
                'priority'          => $data['priority'] ?? 'normale',
                'description'       => $data['description'] ?? null,
                'status'            => 'a_faire',
                'is_auto_generated' => false,
            ]);

            return ['status' => 'success', 'server_id' => $task->id];
        });
    }

    private function denied(): array
    {
        return ['status' => 'permission_denied', 'message' => __('Permission insuffisante.')];
    }

    private function invalid(array $errors): array
    {
        return ['status' => 'validation_failed', 'errors' => $errors];
    }
}
