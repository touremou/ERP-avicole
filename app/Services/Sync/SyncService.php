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
            'sale.create'            => 'saleCreate',
            'expense.create'         => 'expenseCreate',
            'batch.upsert'           => 'batchUpsert',
            'health_incident.create' => 'healthIncidentCreate',
            // Phase 3 — cultures, abattoir, provenderie (rfc-cadrage §MoSCoW).
            'harvest.create'          => 'harvestCreate',
            'crop_input.create'       => 'cropInputCreate',
            'slaughter.execute'       => 'slaughterExecute',
            'mill_production.complete' => 'millProductionComplete',
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
            'uuid'               => 'required|uuid',
            'batch_id'           => 'required|integer|exists:batches,id',
            'check_date'         => 'required|date',
            'mortality'          => 'nullable|integer|min:0',
            'avg_weight'         => 'nullable|numeric|min:0',
            'water_consumed'     => 'nullable|numeric|min:0',
            'feed_consumed'      => 'nullable|numeric|min:0',
            'feed_type'          => 'nullable|string|max:100',
            'humidity'           => 'nullable|numeric|min:0|max:100',
            'observations'       => 'nullable|string|max:1000',
            'qty_quarantine_in'  => 'nullable|integer|min:0',
            'qty_quarantine_out' => 'nullable|integer|min:0',
            'qty_sorted_out'     => 'nullable|integer|min:0',
        ]);

        if ($v->fails()) {
            return $this->invalid($v->errors()->toArray());
        }

        $data = $v->validated();
        $data['feed_type'] = $data['feed_type'] ?? '';
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
            'batch_id'             => 'required|integer|exists:batches,id',
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
            'stock_id' => 'required|integer|exists:stocks,id',
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

    private function saleCreate(array $payload): array
    {
        if (Gate::denies('commerce.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'                 => 'required|uuid',
            'client_id'            => 'required|integer|exists:clients,id',
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
            'batch_id'       => 'nullable|integer|exists:batches,id',
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
            'building_id'            => 'required|integer|exists:buildings,id',
            'initial_quantity'       => 'required|integer|min:1',
            'current_quantity'       => 'required|integer|min:0',
            'status'                 => 'nullable|string|in:Actif,Terminé',
            'arrival_date'           => 'required|date',
            'employee_id'            => 'nullable|integer|exists:employees,id',
            'provider_id'            => 'nullable|integer|exists:providers,id',
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
    private function healthIncidentCreate(array $payload): array
    {
        if (Gate::denies('elevage.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'batch_id'        => 'required|integer|exists:batches,id',
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

    private function harvestCreate(array $payload): array
    {
        if (Gate::denies('cultures.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'crop_cycle_id'   => 'required|integer|exists:crop_cycles,id',
            'harvest_date'    => 'required|date|before_or_equal:today',
            'quantity'        => 'required|numeric|min:0.001',
            'unit'            => 'nullable|string|max:20',
            'net_weight_kg'   => 'nullable|numeric|min:0',
            'loss_quantity'   => 'nullable|numeric|min:0',
            'quality'         => 'nullable|in:' . implode(',', Harvest::QUALITIES),
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
    //  INTRANT (cultures) — coût total dérivé, entrée stock optionnelle.
    // ─────────────────────────────────────────────────────────────

    private function cropInputCreate(array $payload): array
    {
        if (Gate::denies('cultures.C')) {
            return $this->denied();
        }

        $v = Validator::make($payload, [
            'uuid'            => 'required|uuid',
            'crop_cycle_id'   => 'required|integer|exists:crop_cycles,id',
            'type'            => 'required|in:' . implode(',', array_keys(CropInput::TYPES)),
            'name'            => 'required|string|max:255',
            'input_date'      => 'required|date|before_or_equal:today',
            'quantity'        => 'nullable|numeric|min:0',
            'unit'            => 'nullable|string|max:20',
            'unit_cost'       => 'nullable|numeric|min:0',
            'total_cost'      => 'nullable|numeric|min:0',
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
            'slaughter_order_id'      => 'required|integer|exists:slaughter_orders,id',
            'execution_date'          => 'required|date|before_or_equal:today',
            'actual_quantity'         => 'required|integer|min:1',
            'total_live_weight_kg'    => 'required|numeric|min:0.1',
            'total_carcass_weight_kg' => 'required|numeric|min:0.1|lte:total_live_weight_kg',
            'condemned_count'         => 'nullable|integer|min:0',
            'condemned_reason'        => 'nullable|string|max:500',
            'inspector_notes'         => 'nullable|string|max:1000',
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
            'mill_production_id' => 'required|integer|exists:mill_productions,id',
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

    private function denied(): array
    {
        return ['status' => 'permission_denied', 'message' => __('Permission insuffisante.')];
    }

    private function invalid(array $errors): array
    {
        return ['status' => 'validation_failed', 'errors' => $errors];
    }
}
