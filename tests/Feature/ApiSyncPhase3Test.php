<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Farm;
use App\Models\Formula;
use App\Models\Harvest;
use App\Models\MillProduction;
use App\Models\Module;
use App\Models\Plot;
use App\Models\RawMaterial;
use App\Models\Role;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * API v1 — Phase 3 mobile : cultures, abattoir, provenderie.
 *
 * Les quatre nouvelles opérations de /sync/push :
 *   harvest.create / crop_input.create  (cultures.C)
 *   slaughter.execute                   (abattoir.M)
 *   mill_production.complete            (provenderie.M)
 * et les nouveaux référentiels de /sync/pull (plots, crop_cycles,
 * slaughter_orders, formulas, mill_productions).
 *
 * Mêmes invariants que ApiSyncTest : idempotence uuid (rejeu réseau),
 * conflits métier non rejouables, permissions par module.
 */

beforeEach(function () {
    $this->farmA = Farm::firstOrCreate(['code' => 'FA-001'], ['name' => 'Ferme A', 'is_active' => true]);

    $makeRole = function (string $name, array $perms) {
        $role = Role::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
        );

        $now = now();
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $role;
    };

    $manager = $makeRole('manager', ['L', 'C', 'M']);
    $viewer  = $makeRole('viewer', ['L']);

    $this->manager = User::factory()->create(['role_id' => $manager->id]);
    $this->viewer  = User::factory()->create(['role_id' => $viewer->id]);

    foreach ([$this->manager, $this->viewer] as $user) {
        DB::table('farm_user')->insert([
            'farm_id'    => $this->farmA->id,
            'user_id'    => $user->id,
            'is_default' => true,
            'is_owner'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    session(['current_farm_id' => $this->farmA->id]);
});

function phase3Ops(array $operations): array
{
    return ['operations' => array_map(fn ($op) => [
        'op_uuid' => $op['op_uuid'] ?? (string) Str::uuid(),
        'type'    => $op['type'],
        'payload' => $op['payload'],
    ], $operations)];
}

/** Parcelle + cycle en cours, prêts pour une saisie terrain. */
function makeCropCycle(array $overrides = []): CropCycle
{
    $plot = Plot::create([
        'code'    => 'PARC-' . Str::random(4),
        'name'    => 'Parcelle test',
        'area_ha' => 2,
        'status'  => Plot::STATUS_EN_CULTURE,
    ]);

    return CropCycle::create(array_merge([
        'plot_id'       => $plot->id,
        'code'          => 'CYC-' . Str::random(4),
        'crop_name'     => 'Maïs',
        'variety'       => 'Local',
        'area_used_ha'  => 1.5,
        'planting_date' => now()->subDays(60)->toDateString(),
        'status'        => CropCycle::STATUS_EN_COURS,
    ], $overrides));
}

/** Ordre d'abattage planifié sur un lot actif. */
function makeSlaughterOrder(User $requester, int $plannedQty = 60): SlaughterOrder
{
    $batch = Batch::factory()->create([
        'building_id'      => Building::factory()->create(['type' => 'chair'])->id,
        'status'           => 'Actif',
        'initial_quantity' => 100,
        'current_quantity' => 100,
    ]);

    return SlaughterOrder::create([
        'order_number'     => SlaughterOrder::generateNumber(),
        'batch_id'         => $batch->id,
        'planned_date'     => now()->toDateString(),
        'planned_quantity' => $plannedQty,
        'status'           => 'planifie',
        'requested_by'     => $requester->id,
    ]);
}

/** OP provenderie « En cours » adossé à une formule mono-MP approvisionnée. */
function makeMillProduction(float $stockQty = 2000, float $unitCost = 200): MillProduction
{
    $material = RawMaterial::factory()->create([
        'name'      => 'Maïs sync ' . Str::random(4),
        'stock_qty' => $stockQty,
        'unit_cost' => $unitCost,
    ]);

    $formula = Formula::factory()->create(['target_type' => 'chair']);
    $formula->items()->create([
        'raw_material_id' => $material->id,
        'percentage'      => 100,
        'quantity_kg'     => 1000,
    ]);

    return MillProduction::factory()->create([
        'formula_id'        => $formula->id,
        'quantity_produced' => 500,
        'status'            => 'En cours',
    ]);
}

// ─── CULTURES : harvest.create ───

test('harvest.create enregistre la récolte et bascule le cycle en phase récolte', function () {
    $cycle = makeCropCycle();
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();
    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'harvest.create',
        'payload' => [
            'uuid'          => $uuid,
            'crop_cycle_id' => $cycle->id,
            'harvest_date'  => now()->toDateString(),
            'quantity'      => 120.5,
            'unit'          => 'kg',
            'quality'       => 'bon',
            'notes'         => 'Saisie terrain',
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('success');

    $harvest = Harvest::withoutGlobalScopes()->where('uuid', $uuid)->first();
    expect($harvest)->not->toBeNull()
        ->and((float) $harvest->quantity)->toBe(120.5)
        ->and((float) $harvest->net_weight_kg)->toBe(120.5) // déduit (unité kg)
        ->and($harvest->is_synced)->toBeTrue();

    expect($cycle->fresh()->status)->toBe(CropCycle::STATUS_RECOLTE);
});

test('harvest.create est idempotent (rejeu réseau → already_synced)', function () {
    $cycle = makeCropCycle();
    Sanctum::actingAs($this->manager);

    $payload = [
        'uuid'          => (string) Str::uuid(),
        'crop_cycle_id' => $cycle->id,
        'harvest_date'  => now()->toDateString(),
        'quantity'      => 50,
    ];

    $this->postJson('/api/v1/sync/push', phase3Ops([['type' => 'harvest.create', 'payload' => $payload]]))->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', phase3Ops([['type' => 'harvest.create', 'payload' => $payload]]))->assertOk();

    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(Harvest::withoutGlobalScopes()->where('uuid', $payload['uuid'])->count())->toBe(1);
});

test('harvest.create sur un cycle clos → conflict (bac « À corriger »)', function () {
    $cycle = makeCropCycle(['status' => CropCycle::STATUS_TERMINE]);
    Sanctum::actingAs($this->manager);

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'harvest.create',
        'payload' => [
            'uuid'          => (string) Str::uuid(),
            'crop_cycle_id' => $cycle->id,
            'harvest_date'  => now()->toDateString(),
            'quantity'      => 10,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('conflict');
    expect(Harvest::withoutGlobalScopes()->count())->toBe(0);
});

// ─── CULTURES : crop_input.create ───

test("crop_input.create enregistre l'intrant et dérive le coût total", function () {
    $cycle = makeCropCycle();
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();
    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'crop_input.create',
        'payload' => [
            'uuid'          => $uuid,
            'crop_cycle_id' => $cycle->id,
            'type'          => 'engrais',
            'name'          => 'Urée 46%',
            'input_date'    => now()->toDateString(),
            'quantity'      => 10,
            'unit'          => 'kg',
            'unit_cost'     => 500,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('success');

    $input = CropInput::withoutGlobalScopes()->where('uuid', $uuid)->first();
    expect($input)->not->toBeNull()
        ->and((float) $input->total_cost)->toBe(5000.0)
        ->and($input->is_synced)->toBeTrue();
});

test('crop_input.create exige cultures.C (viewer → permission_denied)', function () {
    $cycle = makeCropCycle();
    Sanctum::actingAs($this->viewer);

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'crop_input.create',
        'payload' => [
            'uuid'          => (string) Str::uuid(),
            'crop_cycle_id' => $cycle->id,
            'type'          => 'engrais',
            'name'          => 'Urée',
            'input_date'    => now()->toDateString(),
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('permission_denied');
});

// ─── ABATTOIR : slaughter.execute ───

test("slaughter.execute exécute l'ordre : résultat créé, ordre terminé, rejeu inoffensif", function () {
    $order = makeSlaughterOrder($this->manager);
    Sanctum::actingAs($this->manager);

    $payload = [
        'uuid'                    => (string) Str::uuid(),
        'slaughter_order_id'      => $order->id,
        'execution_date'          => now()->toDateString(),
        'actual_quantity'         => 60,
        'total_live_weight_kg'    => 120,
        'total_carcass_weight_kg' => 90,
    ];

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([['type' => 'slaughter.execute', 'payload' => $payload]]))->assertOk();
    expect($response->json('results.0.status'))->toBe('success');

    $result = SlaughterResult::withoutGlobalScopes()->where('uuid', $payload['uuid'])->first();
    expect($result)->not->toBeNull()
        ->and((float) $result->carcass_yield_percent)->toBe(75.0)
        ->and($order->fresh()->status)->not->toBe('planifie');

    // Rejeu réseau du même uuid : aucun double abattage.
    $replay = $this->postJson('/api/v1/sync/push', phase3Ops([['type' => 'slaughter.execute', 'payload' => $payload]]))->assertOk();
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(SlaughterResult::withoutGlobalScopes()->count())->toBe(1);
});

test("slaughter.execute sur un ordre déjà exécuté ailleurs → conflict", function () {
    $order = makeSlaughterOrder($this->manager);
    Sanctum::actingAs($this->manager);

    // Exécuté « en ligne » entre-temps (autre uuid).
    app(\App\Services\SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity'         => 60,
        'total_live_weight_kg'    => 120,
        'total_carcass_weight_kg' => 90,
        'execution_date'          => now()->toDateString(),
    ]);

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'slaughter.execute',
        'payload' => [
            'uuid'                    => (string) Str::uuid(),
            'slaughter_order_id'      => $order->id,
            'execution_date'          => now()->toDateString(),
            'actual_quantity'         => 60,
            'total_live_weight_kg'    => 120,
            'total_carcass_weight_kg' => 90,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('conflict');
    expect(SlaughterResult::withoutGlobalScopes()->count())->toBe(1);
});

test('slaughter.execute refuse une carcasse plus lourde que le vif (validation_failed)', function () {
    $order = makeSlaughterOrder($this->manager);
    Sanctum::actingAs($this->manager);

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'slaughter.execute',
        'payload' => [
            'uuid'                    => (string) Str::uuid(),
            'slaughter_order_id'      => $order->id,
            'execution_date'          => now()->toDateString(),
            'actual_quantity'         => 60,
            'total_live_weight_kg'    => 100,
            'total_carcass_weight_kg' => 150,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('validation_failed');
});

// ─── PROVENDERIE : mill_production.complete ───

test("mill_production.complete clôture l'OP (MP consommées, silo crédité), rejeu inoffensif", function () {
    $production = makeMillProduction();
    Sanctum::actingAs($this->manager);

    $payload = [
        'uuid'               => (string) Str::uuid(),
        'mill_production_id' => $production->id,
    ];

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([['type' => 'mill_production.complete', 'payload' => $payload]]))->assertOk();
    expect($response->json('results.0.status'))->toBe('success');

    $fresh = $production->fresh();
    expect($fresh->status)->toBe('Terminé')
        ->and($fresh->completion_uuid)->toBe($payload['uuid'])
        ->and((float) $fresh->real_cost_per_kg)->toBe(200.0);

    // 100 % × 500 kg consommés sur la MP.
    $material = $production->formula->items()->first()->rawMaterial;
    expect((float) $material->fresh()->stock_qty)->toBe(1500.0);

    $replay = $this->postJson('/api/v1/sync/push', phase3Ops([['type' => 'mill_production.complete', 'payload' => $payload]]))->assertOk();
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect((float) $material->fresh()->stock_qty)->toBe(1500.0); // pas de double déstockage
});

test('mill_production.complete sur un OP déjà clôturé en ligne → conflict', function () {
    $production = makeMillProduction();
    Sanctum::actingAs($this->manager);

    app(\App\Actions\MillProduction\CompleteMillProduction::class)->execute($production);

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'mill_production.complete',
        'payload' => [
            'uuid'               => (string) Str::uuid(),
            'mill_production_id' => $production->id,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('conflict');
});

test('mill_production.complete avec stock MP insuffisant → conflict avec le motif', function () {
    $production = makeMillProduction(stockQty: 100); // besoin : 500 kg
    Sanctum::actingAs($this->manager);

    $response = $this->postJson('/api/v1/sync/push', phase3Ops([[
        'type'    => 'mill_production.complete',
        'payload' => [
            'uuid'               => (string) Str::uuid(),
            'mill_production_id' => $production->id,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('conflict')
        ->and($response->json('results.0.message'))->toContain('Stock insuffisant');
    expect($production->fresh()->status)->toBe('En cours');
});

// ─── PULL : nouveaux référentiels Phase 3 ───

test('pull expose les référentiels cultures/abattoir/provenderie (liste blanche)', function () {
    $cycle = makeCropCycle();
    $order = makeSlaughterOrder($this->manager);
    $production = makeMillProduction();
    Sanctum::actingAs($this->manager);

    $response = $this->getJson('/api/v1/sync/pull')->assertOk();

    foreach (['plots', 'crop_cycles', 'slaughter_orders', 'formulas', 'mill_productions'] as $entity) {
        expect($response->json("entities.{$entity}.upserts"))->not->toBeEmpty();
    }

    $pulledCycle = collect($response->json('entities.crop_cycles.upserts'))->firstWhere('id', $cycle->id);
    expect($pulledCycle)->toHaveKeys(['id', 'uuid', 'plot_id', 'code', 'crop_name', 'status', 'planting_date'])
        ->and($pulledCycle)->not->toHaveKey('total_revenue'); // le financier ne sort pas

    $pulledOrder = collect($response->json('entities.slaughter_orders.upserts'))->firstWhere('id', $order->id);
    expect($pulledOrder['order_number'])->toBe($order->order_number)
        ->and($pulledOrder['status'])->toBe('planifie');

    $pulledOp = collect($response->json('entities.mill_productions.upserts'))->firstWhere('id', $production->id);
    expect($pulledOp['status'])->toBe('En cours')
        ->and($pulledOp)->not->toHaveKey('real_cost_per_kg'); // idem coût
});
