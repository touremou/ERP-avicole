<?php

use App\Models\Batch;
use App\Models\FeedPurchase;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * M3 — Magasin au terrain : le magasinier compte DEVANT le rayon et réceptionne
 * le camion AU PORTAIL. Les deux ops rejouent les règles web (ajustement
 * valorisé au CMP + alerte, entrée de stock au coût réel + facture fournisseur)
 * et sont idempotentes — un rejeu ne doit pas ajuster ni créditer deux fois.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->stock = Stock::create([
        'item_name' => 'Maïs concassé', 'category' => 'conso', 'unit' => 'KG',
        'current_quantity' => 100, 'alert_threshold' => 20, 'last_unit_price' => 5000,
    ]);
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-M3', 'initial_quantity' => 200, 'current_quantity' => 200, 'qty_alive' => 200,
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

function countOp(int $stockId, float $counted, array $overrides = []): array
{
    return ['op_uuid' => (string) Str::uuid(), 'type' => 'inventory_count.create',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(),
            'stock_id' => $stockId,
            'counted_quantity' => $counted,
            'count_date' => now()->toDateString(),
        ], $overrides)];
}

test('inventaire : un écart NÉGATIF recale le stock et chiffre la démarque', function () {
    // Comptage 92 sur 100 théoriques → manque 8 kg à 5 000 = 40 000 de perte.
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [countOp($this->stock->id, 92)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $adjustment = StockAdjustment::first();
    expect((float) $this->stock->fresh()->current_quantity)->toEqualWithDelta(92, 0.001)
        ->and($adjustment->type)->toBe('perte')
        ->and($adjustment->reason)->toBe('inventaire')
        ->and((float) $adjustment->delta)->toEqualWithDelta(-8, 0.001)
        ->and((float) $adjustment->value_impact)->toEqualWithDelta(40000, 0.01)
        // Le flux physique est tracé en parallèle (traçabilité des mouvements).
        ->and(StockMovement::where('type', 'adjustment')->count())->toBe(1);
});

test('inventaire : un écart POSITIF est un gain', function () {
    $this->postJson('/api/v1/sync/push', ['operations' => [countOp($this->stock->id, 105)]])->assertOk();

    expect(StockAdjustment::first()->type)->toBe('gain')
        ->and((float) $this->stock->fresh()->current_quantity)->toEqualWithDelta(105, 0.001);
});

test('inventaire SANS écart : succès métier, aucun ajustement créé', function () {
    // Le comptage CONFIRME le stock : ce n'est pas une saisie à corriger.
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [countOp($this->stock->id, 100)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('success')
        ->and(StockAdjustment::count())->toBe(0)
        ->and((float) $this->stock->fresh()->current_quantity)->toEqualWithDelta(100, 0.001);
});

test('inventaire : idempotence (le rejeu ne réajuste pas)', function () {
    $op = countOp($this->stock->id, 90);

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(StockAdjustment::count())->toBe(1)
        ->and((float) $this->stock->fresh()->current_quantity)->toEqualWithDelta(90, 0.001);
});

test('réception d\'aliment : crédite le magasin au coût réel et trace l\'achat', function () {
    // 10 sacs de 50 kg = 500 kg pour 2 500 000 → 5 000 /kg.
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'feed_purchase.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'batch_id' => $this->batch->id,
            'purchase_date' => now()->toDateString(),
            'feed_type' => 'Démarrage chair',
            'quantity' => 10,
            'unit_price' => 2500000,
            'unit' => 'Sac',
            'supplier' => 'Provenderie Kindia',
            'payment_mode' => 'comptant',
            'metadata' => ['bag_weight' => 50],
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $purchase = FeedPurchase::first();
    expect($purchase->feed_type)->toBe('Démarrage chair')
        ->and((float) $purchase->total_price)->toEqualWithDelta(2500000, 0.01);

    // L'article de stock est créé/crédité par l'action (source unique du web).
    $created = Stock::where('item_name', 'Démarrage chair')->first();
    expect($created)->not->toBeNull()
        ->and((float) $created->current_quantity)->toBeGreaterThan(0);

    // Achat comptant → facture fournisseur soldée (registre AP).
    expect(\App\Models\SupplierInvoice::where('feed_purchase_id', $purchase->id)->exists())->toBeTrue();
});

test('réception d\'aliment : idempotence (le rejeu ne crédite pas deux fois)', function () {
    $op = ['op_uuid' => (string) Str::uuid(), 'type' => 'feed_purchase.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'batch_id' => $this->batch->id,
            'purchase_date' => now()->toDateString(),
            'feed_type' => 'Croissance',
            'quantity' => 5,
            'unit_price' => 500000,
            'unit' => 'Sac',
            'metadata' => ['bag_weight' => 50],
        ]];

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $qtyAfterFirst = (float) Stock::where('item_name', 'Croissance')->value('current_quantity');

    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(FeedPurchase::count())->toBe(1)
        ->and((float) Stock::where('item_name', 'Croissance')->value('current_quantity'))
            ->toEqualWithDelta($qtyAfterFirst, 0.001);
});

test('réception à crédit : facture fournisseur non soldée (dette)', function () {
    $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'feed_purchase.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'batch_id' => $this->batch->id,
            'purchase_date' => now()->toDateString(),
            'feed_type' => 'Finition',
            'quantity' => 4,
            'unit_price' => 800000,
            'unit' => 'Sac',
            'supplier' => 'Provenderie Kindia',
            'payment_mode' => 'credit',
            'metadata' => ['bag_weight' => 50],
        ],
    ]]])->assertOk();

    $invoice = \App\Models\SupplierInvoice::where('feed_purchase_id', FeedPurchase::first()->id)->first();
    expect($invoice)->not->toBeNull()
        // À crédit : aucun règlement → la dette reste ouverte.
        ->and(\App\Models\SupplierPayment::where('supplier_invoice_id', $invoice->id)->exists())->toBeFalse();
});

test('inventaire : article hors ferme → validation_failed', function () {
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [countOp(999999, 10)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(StockAdjustment::count())->toBe(0);
});
