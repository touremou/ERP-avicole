<?php

use App\Models\Client;
use App\Models\Sale;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function clientPayload(array $overrides = []): array
{
    return array_merge([
        'name'         => 'Boutique Centrale',
        'type'         => 'particulier',
        'category'     => 'detaillant',
        'phone'        => '622990011',
        'credit_limit' => 0,
    ], $overrides);
}

test('un visiteur (L) ne peut pas créer de client', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('clients.store'), clientPayload())
        ->assertRedirect();

    expect(Client::count())->toBe(0);
});

test('un opérateur (C, sans M) ne peut pas modifier un client', function () {
    $client = Client::create(array_merge(clientPayload(['name' => 'Client à modifier']), [
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-2026-020',
        'balance'   => 0,
        'status'    => 'actif',
    ]));

    $this->actingAs($this->operatorUser)
        ->put(route('clients.update', $client), clientPayload([
            'name'   => 'Nom modifié',
            'status' => 'actif',
        ]))
        ->assertRedirect();

    expect($client->fresh()->name)->toBe('Client à modifier');
});

test('un manager (sans S) ne peut pas supprimer un client', function () {
    $client = Client::create(array_merge(clientPayload(['name' => 'Client protégé']), [
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-2026-021',
        'balance'   => 0,
        'status'    => 'actif',
    ]));

    $this->actingAs($this->managerUser)
        ->delete(route('clients.destroy', $client))
        ->assertRedirect();

    expect(Client::find($client->id))->not->toBeNull();
});

test('un client avec un solde dû ne peut pas être supprimé', function () {
    $client = Client::create(array_merge(clientPayload(['name' => 'Client débiteur']), [
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-2026-022',
        'balance'   => 50000,
        'status'    => 'actif',
    ]));

    $this->actingAs($this->adminUser)
        ->delete(route('clients.destroy', $client))
        ->assertSessionHas('error');

    expect(Client::find($client->id))->not->toBeNull();
});

test('un client avec un historique de ventes ne peut pas être supprimé même à solde nul', function () {
    $client = Client::create(array_merge(clientPayload(['name' => 'Client historique']), [
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-2026-023',
        'balance'   => 0,
        'status'    => 'actif',
    ]));

    Sale::create([
        'farm_id'        => $this->farm->id,
        'client_id'      => $client->id,
        'user_id'        => $this->adminUser->id,
        'reference'      => 'BL-2026-000990',
        'sale_date'      => now()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'valide',
        'subtotal'       => 10000,
        'tax_amount'     => 0,
        'total_amount'   => 10000,
        'paid_amount'    => 10000,
        'payment_status' => 'solde',
    ]);

    $this->actingAs($this->adminUser)
        ->delete(route('clients.destroy', $client))
        ->assertSessionHas('error');

    expect(Client::find($client->id))->not->toBeNull();
});

test('une vente vers un client suspendu est refusée', function () {
    $client = Client::create(array_merge(clientPayload(['name' => 'Client suspendu']), [
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-2026-024',
        'balance'   => 0,
        'status'    => 'suspendu',
    ]));

    $this->actingAs($this->adminUser)
        ->post(route('sales.store'), [
            'client_id' => $client->id,
            'sale_date' => now()->toDateString(),
            'type'      => 'bon_livraison',
            'tax_rate'  => 0,
            'items'     => [[
                'product_type' => 'oeufs',
                'product_name' => 'Œufs calibre M',
                'quantity'     => 5,
                'unit'         => 'alveole',
                'unit_price'   => 1000,
            ]],
        ])
        ->assertSessionHasErrors('client_id');

    expect(Sale::count())->toBe(0);
});

test('une vente dépassant le plafond de crédit du client est refusée', function () {
    $client = Client::create(array_merge(clientPayload(['name' => 'Client à crédit']), [
        'farm_id'      => $this->farm->id,
        'client_id'    => 'CLI-2026-025',
        'balance'      => 80000,
        'status'       => 'actif',
        'credit_limit' => 100000,
    ]));

    $this->actingAs($this->adminUser)
        ->post(route('sales.store'), [
            'client_id' => $client->id,
            'sale_date' => now()->toDateString(),
            'type'      => 'bon_livraison',
            'tax_rate'  => 0,
            'items'     => [[
                // 50 × 1000 = 50 000 > (100 000 - 80 000) de crédit restant
                'product_type' => 'oeufs',
                'product_name' => 'Œufs calibre M',
                'quantity'     => 50,
                'unit'         => 'alveole',
                'unit_price'   => 1000,
            ]],
        ])
        ->assertSessionHasErrors('client_id');

    expect(Sale::count())->toBe(0);
});
