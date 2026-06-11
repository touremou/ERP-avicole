<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->client = Client::create([
        'farm_id'      => $this->farm->id,
        'client_id'    => 'CLI-2026-001',
        'name'         => 'Boulangerie Diallo',
        'type'         => 'particulier',
        'phone'        => '622334455',
        'credit_limit' => 0,
        'balance'      => 0,
        'status'       => 'actif',
    ]);
});

function salePayload(Client $client, float $qty, float $price): array
{
    return [
        'client_id' => $client->id,
        'sale_date' => now()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'oeufs',
            'product_name' => 'Œufs calibre M',
            'quantity'     => $qty,
            'unit'         => 'alveole',
            'unit_price'   => $price,
        ]],
    ];
}

test('un utilisateur autorisé (C) peut créer une vente avec un total correctement arrondi', function () {
    $this->actingAs($this->adminUser)
        ->post(route('sales.store'), salePayload($this->client, 3, 33.33))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $sale = Sale::first();
    expect($sale)->not->toBeNull();
    // 3 × 33.33 = 99.99 (arrondi maîtrisé à 2 décimales)
    expect((float) $sale->total_amount)->toBe(99.99);
});

test('un visiteur (L) ne peut PAS créer de vente', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('sales.store'), salePayload($this->client, 10, 1500))
        ->assertSessionMissing('success');

    expect(Sale::count())->toBe(0);
});

test('un encaissement met à jour le statut de paiement', function () {
    $sale = Sale::create([
        'farm_id'        => $this->farm->id,
        'client_id'      => $this->client->id,
        'user_id'        => $this->adminUser->id,
        'reference'      => 'BL-2026-000999',
        'sale_date'      => now()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'valide',
        'subtotal'       => 50000,
        'tax_amount'     => 0,
        'total_amount'   => 50000,
        'paid_amount'    => 0,
        'payment_status' => 'impaye',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payments.store'), [
            'sale_id'      => $sale->id,
            'amount'       => 50000,
            'payment_date' => now()->toDateString(),
            'method'       => 'especes',
        ])
        ->assertSessionHas('success');

    expect((float) Payment::where('sale_id', $sale->id)->sum('amount'))->toBe(50000.0);
    expect($sale->fresh()->payment_status)->toBe('solde');
});

test('un encaissement supérieur au reste dû est refusé', function () {
    $sale = Sale::create([
        'farm_id'        => $this->farm->id,
        'client_id'      => $this->client->id,
        'user_id'        => $this->adminUser->id,
        'reference'      => 'BL-2026-000998',
        'sale_date'      => now()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'valide',
        'subtotal'       => 20000,
        'tax_amount'     => 0,
        'total_amount'   => 20000,
        'paid_amount'    => 0,
        'payment_status' => 'impaye',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payments.store'), [
            'sale_id'      => $sale->id,
            'amount'       => 30000, // > 20000
            'payment_date' => now()->toDateString(),
            'method'       => 'especes',
        ])
        ->assertSessionHasErrors('amount');

    expect(Payment::where('sale_id', $sale->id)->count())->toBe(0);
});
