<?php

use App\Models\Batch;
use App\Models\CuttingSession;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Lot 4 (refonte désassemblage) — op de sync « slaughter.cutting » : la découpe
 * se saisit à l'atelier sur mobile (offline) et rejoue TOUTES les règles web
 * au push : ordre terminé, conservation de matière, déchets hors stock,
 * répartition des coûts, idempotence par uuid.
 */

beforeEach(function () {
    $this->setUpRbac();
    // Contexte ferme explicite pour l'API (SetApiFarmContext lit farm_user).
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-SYNC-CUT', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
        'buy_price_per_unit' => 20000,
    ]);
    $this->order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 30,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    $this->actingAs($this->managerUser);
    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 60,
        'total_carcass_weight_kg' => 40, 'execution_date' => now()->toDateString(),
    ]);
    $this->order->refresh();
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

function cuttingOp(SlaughterOrder $order, array $overrides = []): array
{
    return ['op_uuid' => (string) Str::uuid(), 'type' => 'slaughter.cutting',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(),
            'slaughter_order_id' => $order->id,
            'session_date' => now()->toDateString(),
            'total_input_kg' => 20,
            'products' => [
                ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 12, 'destination' => 'stock_frais'],
                ['type' => 'dechet', 'name' => 'Déchets', 'kg' => 3, 'destination' => 'dechet'],
            ],
        ], $overrides)];
}

test('la découpe mobile crée session + produits + stock, déchets exclus', function () {
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [cuttingOp($this->order)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $session = CuttingSession::first();
    expect((float) $session->total_input_kg)->toEqualWithDelta(20.0, 0.01)
        ->and($session->products()->count())->toBe(2)
        // Perte inexpliquée : 20 − (12+3) = 5 kg = 25 %.
        ->and((float) $session->loss_percent)->toEqualWithDelta(25.0, 0.1);

    // Stock : cuisses OUI (coût : 15 000×20/12 kg valorisables = 25 000/kg), déchets NON.
    expect(FinishedProduct::where('product_type', 'cuisse')->exists())->toBeTrue()
        ->and((float) FinishedProduct::where('product_type', 'cuisse')->value('unit_cost'))->toEqualWithDelta(25000, 1)
        ->and(FinishedProduct::where('product_type', 'dechet')->exists())->toBeFalse();
});

test('idempotence : le rejeu du même uuid ne recrée rien', function () {
    $op = cuttingOp($this->order);
    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(CuttingSession::count())->toBe(1);
});

test('conservation de matière : Σ morceaux > entrée → validation_failed', function () {
    $op = cuttingOp($this->order, ['products' => [
        ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 25, 'destination' => 'stock_frais'],
    ]]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('validation_failed')
        ->and(CuttingSession::count())->toBe(0);
});

test('découpe au-delà de la carcasse restante → conflict (bac À corriger)', function () {
    $op = cuttingOp($this->order, ['total_input_kg' => 45, 'products' => [
        ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 40, 'destination' => 'stock_frais'],
    ]]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('conflict');
});

test('type de découpe inconnu pour l\'espèce → validation_failed', function () {
    $op = cuttingOp($this->order, ['products' => [
        ['type' => 'gigot', 'name' => 'Gigot', 'kg' => 5, 'destination' => 'stock_frais'],
    ]]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('validation_failed');
});

test('routage mobile : destination transformation → enfant lié à l\'ordre', function () {
    $op = cuttingOp($this->order, ['products' => [
        ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 12, 'destination' => 'transformation'],
    ]]);

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();

    $child = \App\Models\Transformation::where('slaughter_order_id', $this->order->id)->first();
    expect($child)->not->toBeNull()
        ->and($child->status)->toBe('en_cours')
        ->and((float) $child->input_kg)->toEqualWithDelta(12.0, 0.01);
});
