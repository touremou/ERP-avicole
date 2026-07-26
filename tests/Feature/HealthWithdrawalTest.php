<?php

use App\Models\Batch;
use App\Models\HealthCheck;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * M1 — Soins/vaccinations terrain et DÉLAI D'ATTENTE : après un vaccin ou un
 * traitement, la viande n'est pas consommable avant l'échéance de la notice.
 * Le délai bloque l'abattage (web ET sync), et se lève AUTOMATIQUEMENT à la
 * date — aucune décision qualité, contrairement à la quarantaine.
 */

beforeEach(function () {
    $this->setUpRbac();
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-VAC', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
    ]);
    $this->actingAs($this->managerUser);
});

function careOp(int $batchId, array $overrides = []): array
{
    return ['op_uuid' => (string) Str::uuid(), 'type' => 'health_check.create',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(),
            'batch_id' => $batchId,
            'intervention_date' => now()->toDateString(),
            'type' => 'Vaccin',
            'product_name' => 'Gumboro NDV',
            'dosage' => '1 ml / sujet',
            'mode_administration' => 'Eau de boisson',
            'withdrawal_days' => 7,
        ], $overrides)];
}

test('sync : le soin mobile crée l\'intervention et pose le délai d\'attente', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [careOp($this->batch->id)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $check = HealthCheck::first();
    expect($check->product_name)->toBe('Gumboro NDV')
        ->and($check->withdrawal_days)->toBe(7)
        ->and($check->isUnderWithdrawal())->toBeTrue()
        ->and($check->withdrawal_until->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($this->batch->fresh()->isUnderWithdrawal())->toBeTrue();
});

test('sync : idempotence du soin (rejeu du même uuid)', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
    $op = careOp($this->batch->id);

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(HealthCheck::count())->toBe(1);
});

test('sync : produit périmé au jour de l\'intervention → validation_failed', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        careOp($this->batch->id, ['expiry_date' => now()->subDay()->toDateString()]),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(HealthCheck::count())->toBe(0);
});

test("l'abattage est REFUSÉ tant que le délai d'attente court", function () {
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->subDays(2)->toDateString(),
        'type' => 'Traitement', 'product_name' => 'Oxytétracycline',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => 10,
    ]);

    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 20,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);

    expect(fn () => app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity' => 20, 'total_live_weight_kg' => 40,
        'total_carcass_weight_kg' => 28, 'execution_date' => now()->toDateString(),
    ]))->toThrow(Exception::class, "DÉLAI D'ATTENTE");

    expect($order->fresh()->status)->toBe('planifie');
});

test("le délai d'attente purgé libère l'abattage (levée automatique)", function () {
    // Traitement de 10 j administré il y a 12 j → délai expiré.
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->subDays(12)->toDateString(),
        'type' => 'Traitement', 'product_name' => 'Oxytétracycline',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => 10,
    ]);

    expect($this->batch->fresh()->isUnderWithdrawal())->toBeFalse();

    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 20,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);

    app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity' => 20, 'total_live_weight_kg' => 40,
        'total_carcass_weight_kg' => 28, 'execution_date' => now()->toDateString(),
    ]);

    expect($order->fresh()->status)->toBe('termine');
});

test("une intervention sans délai (vitamine) ne bloque rien", function () {
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->toDateString(),
        'type' => 'Vitamine', 'product_name' => 'Complexe AD3E',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => null,
    ]);

    expect($this->batch->fresh()->isUnderWithdrawal())->toBeFalse();
});

test("création d'ordre : date planifiée avant l'échéance du délai refusée", function () {
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->toDateString(),
        'type' => 'Vaccin', 'product_name' => 'Gumboro NDV',
        'mode_administration' => 'Eau de boisson', 'withdrawal_days' => 21,
    ]);

    // Dans 5 jours : encore sous délai → refus.
    $this->post(route('slaughter.orders.store'), [
        'batch_id' => $this->batch->id,
        'planned_date' => now()->addDays(5)->toDateString(),
        'planned_quantity' => 20,
    ])->assertSessionHasErrors('planned_date');

    // Après l'échéance : autorisé (le contrôle sera rejoué à l'exécution).
    $this->post(route('slaughter.orders.store'), [
        'batch_id' => $this->batch->id,
        'planned_date' => now()->addDays(25)->toDateString(),
        'planned_quantity' => 20,
    ])->assertRedirect(route('slaughter.dashboard'))->assertSessionHas('success');
});

test('web : le formulaire de soin accepte et enregistre le délai d\'attente', function () {
    $this->post(route('health.store'), [
        'batch_id' => $this->batch->id,
        'intervention_date' => now()->toDateString(),
        'type' => 'Vaccin',
        'product_name' => 'Newcastle',
        'mode_administration' => 'Injection',
        'withdrawal_days' => 14,
    ])->assertRedirect(route('health.index'));

    expect(HealthCheck::first()->withdrawal_days)->toBe(14);
});

test("le lot le plus contraignant fixe l'échéance (deux interventions)", function () {
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->toDateString(),
        'type' => 'Vaccin', 'product_name' => 'Court', 'mode_administration' => 'Eau de boisson',
        'withdrawal_days' => 3,
    ]);
    HealthCheck::create([
        'batch_id' => $this->batch->id, 'intervention_date' => now()->toDateString(),
        'type' => 'Traitement', 'product_name' => 'Long', 'mode_administration' => 'Injection',
        'withdrawal_days' => 15,
    ]);

    $active = $this->batch->fresh()->activeWithdrawal();
    expect($active->product_name)->toBe('Long')
        ->and($active->withdrawal_days_left)->toBe(15);
});
