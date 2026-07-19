<?php

use App\Models\Batch;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Lot D — clôture de cycle (checklist HACCP / déchets). N'est possible qu'après
 * exécution ; exige les 3 confirmations obligatoires ; idempotente ; tracée au
 * dossier de lot.
 */

beforeEach(function () {
    $this->setUpRbac();
    // Contexte ferme explicite pour l'API (SetApiFarmContext lit farm_user).
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-CLOS', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
    ]);
    $this->order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 60,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    $this->actingAs($this->managerUser);
    // Exécute l'abattage → statut « terminé ».
    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 60, 'total_live_weight_kg' => 120,
        'total_carcass_weight_kg' => 90, 'execution_date' => now()->toDateString(),
    ]);
    $this->order->refresh();
});

test('la clôture exige les 3 confirmations (sans elles → rejet, cycle non clos)', function () {
    $this->post(route('slaughter.closure.store', $this->order), [
        'waste_evacuated' => 1, 'zone_cleaned' => 1, // marche_avant manquante
    ])->assertSessionHasErrors('marche_avant');

    expect($this->order->fresh()->isClosed())->toBeFalse();
});

test('avec les 3 confirmations, le cycle se clôture et enregistre la checklist', function () {
    $this->post(route('slaughter.closure.store', $this->order), [
        'waste_evacuated' => 1, 'zone_cleaned' => 1, 'marche_avant' => 1,
        'waste_destination' => 'Équarrissage', 'notes' => 'RAS',
    ])->assertRedirect();

    $order = $this->order->fresh();
    expect($order->isClosed())->toBeTrue()
        ->and($order->closed_by)->toBe($this->managerUser->id)
        ->and(data_get($order->closure_checklist, 'waste_destination'))->toBe('Équarrissage')
        ->and(data_get($order->closure_checklist, 'auto_checks.byproducts_recorded'))->toBeFalse();
});

test('la clôture est impossible avant exécution (ordre planifié)', function () {
    $planned = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 10,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);

    $this->get(route('slaughter.closure.form', $planned))->assertRedirect(route('slaughter.dashboard'));
});

test('le dossier de lot affiche la clôture signée', function () {
    app(\App\Actions\Slaughter\CloseSlaughterCycle::class)->execute($this->order, [
        'waste_evacuated' => true, 'zone_cleaned' => true, 'marche_avant' => true, 'waste_destination' => 'Compost',
    ]);

    $this->get(route('slaughter.orders.traceability', $this->order))
        ->assertOk()
        ->assertSee('Clôture de cycle', false)
        ->assertSee('Clos', false)
        ->assertSee('Compost', false);
});

test('sync : slaughter.close clôture le cycle, idempotent au rejeu', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
    $op = fn () => ['op_uuid' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'slaughter.close',
        'payload' => ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'slaughter_order_id' => $this->order->id,
            'waste_evacuated' => true, 'zone_cleaned' => true, 'marche_avant' => true]];

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op()]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('success');
    expect($this->order->fresh()->isClosed())->toBeTrue();

    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op()]])->assertOk()->json('results.0');
    expect($replay['status'])->toBe('already_synced');
});

test('sync : slaughter.close sans confirmation → validation_failed', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'slaughter.close',
        'payload' => ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'slaughter_order_id' => $this->order->id,
            'waste_evacuated' => true], // zone_cleaned + marche_avant manquants
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed');
    expect($this->order->fresh()->isClosed())->toBeFalse();
});
