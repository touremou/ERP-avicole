<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * M2 — Argent au terrain : le livreur encaisse une créance et reprend de la
 * marchandise CHEZ le client, hors réseau. Les deux ops rejouent les règles
 * web (reste dû sous verrou, remise en stock) et sont idempotentes : un rejeu
 * ne double jamais un encaissement (sinon solde client et caisse faussés).
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->client = Client::create(['client_id' => 'CL-M2-01', 'name' => 'Boutique Kaloum', 'type' => 'entreprise']);
    $this->sale = Sale::create([
        'reference' => 'V-M2-001', 'client_id' => $this->client->id, 'user_id' => $this->managerUser->id,
        'sale_date' => now()->toDateString(), 'type' => 'comptoir', 'status' => 'valide',
        'subtotal' => 100000, 'total_amount' => 100000, 'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);
    $this->item = SaleItem::create([
        'sale_id' => $this->sale->id, 'product_type' => 'carcasse', 'product_name' => 'Poulet PAC',
        'quantity' => 10, 'unit' => 'KG', 'unit_price' => 10000, 'total' => 100000,
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

function paymentOp(int $saleId, array $overrides = []): array
{
    return ['op_uuid' => (string) Str::uuid(), 'type' => 'payment.create',
        'payload' => array_merge([
            'uuid' => (string) Str::uuid(),
            'sale_id' => $saleId,
            'amount' => 40000,
            'payment_date' => now()->toDateString(),
            'method' => 'especes',
        ], $overrides)];
}

test("l'encaissement mobile crée le paiement et met à jour le reste dû", function () {
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [paymentOp($this->sale->id)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $sale = $this->sale->fresh();
    expect(Payment::count())->toBe(1)
        ->and((float) $sale->paid_amount)->toEqualWithDelta(40000, 0.01)
        ->and($sale->payment_status)->toBe('partiel')
        ->and((float) $sale->remaining_amount)->toEqualWithDelta(60000, 0.01);
});

test('idempotence : le rejeu du même encaissement ne double pas le paiement', function () {
    $op = paymentOp($this->sale->id);

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(Payment::count())->toBe(1)
        ->and((float) $this->sale->fresh()->paid_amount)->toEqualWithDelta(40000, 0.01);
});

test('encaissement au-delà du reste dû → conflict (bac À corriger)', function () {
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        paymentOp($this->sale->id, ['amount' => 150000]),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('conflict')
        ->and(Payment::count())->toBe(0);
});

test('un encaissement solde la vente et le statut passe à soldé', function () {
    $this->postJson('/api/v1/sync/push', ['operations' => [
        paymentOp($this->sale->id, ['amount' => 100000]),
    ]])->assertOk();

    expect($this->sale->fresh()->payment_status)->toBe('solde');
});

test('le retour mobile réduit la vente et rembourse le trop-perçu', function () {
    // Vente déjà réglée intégralement → la reprise de 3 kg (30 000) crée un
    // trop-perçu du même montant, donc un remboursement effectif.
    $this->postJson('/api/v1/sync/push', ['operations' => [
        paymentOp($this->sale->id, ['amount' => 100000]),
    ]])->assertOk();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'sale_return.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'sale_id' => $this->sale->id,
            'refund_method' => 'especes',
            'reason' => 'Invendu',
            'lines' => [['sale_item_id' => $this->item->id, 'quantity' => 3]],
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $return = SaleReturn::first();
    expect($return)->not->toBeNull()
        ->and((float) $return->total_refund)->toEqualWithDelta(30000, 0.01)
        ->and($return->items()->count())->toBe(1)
        // La vente ne reflète plus que les biens CONSERVÉS (7 kg).
        ->and((float) $this->sale->fresh()->total_amount)->toEqualWithDelta(70000, 0.01);
});

test("retour sans paiement préalable : vente réduite, aucun remboursement", function () {
    $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'sale_return.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'sale_id' => $this->sale->id,
            'refund_method' => 'especes',
            'lines' => [['sale_item_id' => $this->item->id, 'quantity' => 3]],
        ],
    ]]])->assertOk();

    // Rien n'avait été réglé : on ne rend pas d'argent, on réduit la créance.
    expect((float) SaleReturn::first()->total_refund)->toEqualWithDelta(0, 0.01)
        ->and((float) $this->sale->fresh()->total_amount)->toEqualWithDelta(70000, 0.01);
});

test('retour : une ligne étrangère à la vente → validation_failed', function () {
    $otherSale = Sale::create([
        'reference' => 'V-M2-002', 'client_id' => $this->client->id, 'user_id' => $this->managerUser->id,
        'sale_date' => now()->toDateString(), 'type' => 'comptoir', 'status' => 'valide',
        'subtotal' => 5000, 'total_amount' => 5000, 'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);
    $foreign = SaleItem::create([
        'sale_id' => $otherSale->id, 'product_type' => 'oeufs', 'product_name' => 'Œufs L',
        'quantity' => 1, 'unit' => 'plateau', 'unit_price' => 5000, 'total' => 5000,
    ]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'sale_return.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'sale_id' => $this->sale->id,
            'refund_method' => 'especes',
            'lines' => [['sale_item_id' => $foreign->id, 'quantity' => 1]],
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(SaleReturn::count())->toBe(0);
});

test('idempotence du retour (rejeu du même uuid)', function () {
    $op = ['op_uuid' => (string) Str::uuid(), 'type' => 'sale_return.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'sale_id' => $this->sale->id,
            'refund_method' => 'especes',
            'lines' => [['sale_item_id' => $this->item->id, 'quantity' => 2]],
        ]];

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(SaleReturn::count())->toBe(1);
});

test('le pull descend les créances OUVERTES et leurs lignes', function () {
    // Vente soldée : hors périmètre terrain.
    $paid = Sale::create([
        'reference' => 'V-M2-PAID', 'client_id' => $this->client->id, 'user_id' => $this->managerUser->id,
        'sale_date' => now()->toDateString(), 'type' => 'comptoir', 'status' => 'valide',
        'subtotal' => 9000, 'total_amount' => 9000, 'paid_amount' => 9000, 'payment_status' => 'solde',
    ]);
    // Brouillon : jamais encaissable.
    Sale::create([
        'reference' => 'V-M2-DRAFT', 'client_id' => $this->client->id, 'user_id' => $this->managerUser->id,
        'sale_date' => now()->toDateString(), 'type' => 'comptoir', 'status' => 'brouillon',
        'subtotal' => 7000, 'total_amount' => 7000, 'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);

    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    $refs = collect($entities['sales']['upserts'])->pluck('reference');
    expect($refs)->toContain('V-M2-001')
        ->and($refs)->not->toContain('V-M2-PAID')
        ->and($refs)->not->toContain('V-M2-DRAFT');

    // Les lignes de la créance ouverte suivent (base du retour client).
    expect(collect($entities['sale_items']['upserts'])->pluck('id'))->toContain($this->item->id);
});
