<?php

use App\Models\Client;
use App\Models\Farm;
use App\Models\Sale;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->client = Client::create([
        'farm_id'      => $this->farm->id,
        'client_id'    => 'CLI-2026-010',
        'name'         => 'Restaurant Le Sahel',
        'type'         => 'particulier',
        'phone'        => '622334477',
        'credit_limit' => 0,
        'balance'      => 0,
        'status'       => 'actif',
    ]);
});

test('une vente créée hérite automatiquement de la ferme courante', function () {
    $this->actingAs($this->adminUser)
        ->post(route('sales.store'), [
            'client_id' => $this->client->id,
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
        ->assertSessionHasNoErrors();

    expect(Sale::first()->farm_id)->toBe($this->farm->id);
});

test('les ventes d\'une autre ferme sont invisibles depuis la ferme courante', function () {
    $otherFarm = Farm::create(['name' => 'Ferme Voisine', 'code' => 'FT-002', 'is_active' => true]);

    $otherClient = Client::create([
        'farm_id'      => $otherFarm->id,
        'client_id'    => 'CLI-2026-011',
        'name'         => 'Client Ferme Voisine',
        'type'         => 'particulier',
        'phone'        => '622334488',
        'credit_limit' => 0,
        'balance'      => 0,
        'status'       => 'actif',
    ]);

    Sale::create([
        'farm_id'        => $otherFarm->id,
        'client_id'      => $otherClient->id,
        'user_id'        => $this->adminUser->id,
        'reference'      => 'BL-2026-VOISINE-1',
        'sale_date'      => now()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'valide',
        'subtotal'       => 10000,
        'tax_amount'     => 0,
        'total_amount'   => 10000,
        'paid_amount'    => 0,
        'payment_status' => 'impaye',
    ]);

    // Toujours positionné sur $this->farm (FT-001) : la vente de FT-002
    // ne doit pas apparaître dans le listing ni dans les comptes globaux.
    expect(Sale::count())->toBe(0);

    $this->actingAs($this->adminUser)
        ->get(route('sales.index'))
        ->assertOk()
        ->assertDontSee('BL-2026-VOISINE-1');

    expect(Sale::withoutFarm()->count())->toBe(1);
});

test('la numérotation des ventes reste globalement unique entre deux fermes', function () {
    $otherFarm = Farm::create(['name' => 'Ferme Voisine', 'code' => 'FT-003', 'is_active' => true]);

    $payload = fn (Client $client) => [
        'client_id' => $client->id,
        'sale_date' => now()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'oeufs',
            'product_name' => 'Œufs calibre M',
            'quantity'     => 1,
            'unit'         => 'alveole',
            'unit_price'   => 1000,
        ]],
    ];

    // Vente n°1 sur la ferme courante (FT-001).
    $this->actingAs($this->adminUser)
        ->post(route('sales.store'), $payload($this->client))
        ->assertSessionHasNoErrors();

    // Bascule sur une seconde ferme et crée son propre client.
    $otherClient = Client::create([
        'farm_id'      => $otherFarm->id,
        'client_id'    => 'CLI-2026-012',
        'name'         => 'Client Ferme Voisine',
        'type'         => 'particulier',
        'phone'        => '622334499',
        'credit_limit' => 0,
        'balance'      => 0,
        'status'       => 'actif',
    ]);
    session(['current_farm_id' => $otherFarm->id]);

    $this->actingAs($this->adminUser)
        ->post(route('sales.store'), $payload($otherClient))
        ->assertSessionHasNoErrors();

    $references = Sale::withoutFarm()->pluck('reference')->all();
    expect($references)->toHaveCount(2)
        ->and($references[0])->not->toBe($references[1]);
});
