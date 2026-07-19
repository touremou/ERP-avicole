<?php

use App\Models\Provider;
use App\Models\SlaughterOrder;
use App\Models\SlaughterReception;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Solde de réception à vif : un ordre d'abattage (même PARTIEL) réserve ses
 * sujets — la somme des ordres non annulés ne dépasse jamais les acceptés.
 * Registre immuable (RG-06) → solde DÉRIVÉ des ordres liés, rien décrémenté.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);
    // Réception de 100 sujets, 5 écartés à l'ante-mortem → 95 exploitables.
    $this->reception = SlaughterReception::create([
        'provider_id' => Provider::factory()->create()->id,
        'reception_date' => now()->toDateString(),
        'received_quantity' => 100, 'rejected_quantity' => 5, 'total_live_weight_kg' => 180,
        'sanitary_state' => 'conforme', 'fasting_respected' => 'oui',
        'decision' => 'accepte', 'controller_id' => $this->managerUser->id, 'validated_at' => now(),
    ]);
});

function receptionOrder($test, int $qty, string $status = 'planifie'): SlaughterOrder
{
    return SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'reception_id' => $test->reception->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => $qty,
        'status' => $status, 'requested_by' => auth()->id(),
    ]);
}

test('le solde dérive des ordres liés : planifiés en prévu, exécutés en réel, annulés libérés', function () {
    expect($this->reception->remainingQuantity())->toBe(95);

    $planned = receptionOrder($this, 30);
    expect($this->reception->remainingQuantity())->toBe(65);

    // Exécution partielle : 28 abattus en réel (2 de moins que prévu) → le
    // solde se recale sur le RÉEL.
    app(SlaughterService::class)->executeSlaughter($planned, [
        'actual_quantity' => 28, 'total_live_weight_kg' => 50,
        'total_carcass_weight_kg' => 36, 'execution_date' => now()->toDateString(),
    ]);
    expect($this->reception->remainingQuantity())->toBe(67);

    // Un ordre annulé libère son quota.
    $cancelled = receptionOrder($this, 40);
    expect($this->reception->remainingQuantity())->toBe(27);
    $cancelled->update(['status' => 'annule']);
    expect($this->reception->remainingQuantity())->toBe(67);
});

test('création : un ordre au-delà du solde est refusé', function () {
    receptionOrder($this, 60);

    $this->post(route('slaughter.orders.store'), [
        'reception_id' => $this->reception->id,
        'planned_date' => now()->toDateString(),
        'planned_quantity' => 40, // 95 − 60 = 35 restants
    ])->assertSessionHasErrors('planned_quantity');

    expect(SlaughterOrder::where('reception_id', $this->reception->id)->count())->toBe(1);
});

test('création : un ordre dans le solde passe', function () {
    receptionOrder($this, 60);

    $this->post(route('slaughter.orders.store'), [
        'reception_id' => $this->reception->id,
        'planned_date' => now()->toDateString(),
        'planned_quantity' => 35,
    ])->assertRedirect(route('slaughter.dashboard'))->assertSessionHas('success');

    expect($this->reception->remainingQuantity())->toBe(0);
});

test("exécution : le réel abattu ne peut pas dépasser le solde de la réception", function () {
    $order = receptionOrder($this, 50);
    receptionOrder($this, 45); // réserve le reste (95 − 50)

    // L'opérateur tente d'abattre 60 sur un ordre de 50 alors que 45 sont
    // réservés ailleurs : solde hors cet ordre = 95 − 45 = 50 → refus.
    $this->post(route('slaughter.execute.store', $order), [
        'actual_quantity' => 60, 'total_live_weight_kg' => 110,
        'total_carcass_weight_kg' => 80, 'execution_date' => now()->toDateString(),
    ])->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('planifie');
});

test('sync : slaughter.execute au-delà du solde → conflict (bac À corriger)', function () {
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = receptionOrder($this, 50);
    receptionOrder($this, 45);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'slaughter.execute',
        'payload' => ['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'slaughter_order_id' => $order->id, 'execution_date' => now()->toDateString(),
            'actual_quantity' => 60, 'total_live_weight_kg' => 110, 'total_carcass_weight_kg' => 80],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('conflict')
        ->and($order->fresh()->status)->toBe('planifie');
});

test('le formulaire de création masque les réceptions épuisées et affiche le solde', function () {
    // Réception n°1 : 95 exploitables, 60 réservés → « reste 35 / 95 ».
    receptionOrder($this, 60);

    // Seconde réception entièrement consommée → absente de la liste.
    $exhausted = SlaughterReception::create([
        'provider_id' => Provider::factory()->create()->id,
        'reception_date' => now()->toDateString(),
        'received_quantity' => 20, 'rejected_quantity' => 0, 'total_live_weight_kg' => 40,
        'sanitary_state' => 'conforme', 'fasting_respected' => 'oui',
        'decision' => 'accepte', 'controller_id' => $this->managerUser->id, 'validated_at' => now(),
    ]);
    SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'reception_id' => $exhausted->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 20,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);

    $this->get(route('slaughter.orders.create'))
        ->assertOk()
        ->assertSee('reste 35 / 95', false)
        ->assertDontSee('#' . $exhausted->id . ' —', false);
});
