<?php

use App\Models\Client;
use App\Models\PriceList;
use App\Models\Stock;
use App\Services\PricingService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->stock = Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet', 'unit' => 'piece',
        'current_quantity' => 100, 'unit_price' => 0, 'last_unit_price' => 4800, 'alert_threshold' => 5,
    ]);

    foreach (['standard' => 5000, 'detaillant' => 5500, 'grossiste' => 4000] as $tier => $price) {
        PriceList::create([
            'product_type' => 'produits_finis', 'product_name' => 'Poulet', 'category' => $tier,
            'unit' => 'piece', 'unit_price' => $price, 'effective_date' => now()->subDay()->toDateString(), 'is_active' => true,
        ]);
    }

    $this->pricing = new PricingService();
});

test('le palier tarifaire dépend de la catégorie du client', function () {
    $grossiste = Client::create(['farm_id' => $this->farm->id, 'client_id' => 'C1', 'name' => 'G', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0]);
    $detaillant = Client::create(['farm_id' => $this->farm->id, 'client_id' => 'C2', 'name' => 'D', 'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0]);

    expect($this->pricing->tierForClient($grossiste))->toBe('grossiste')
        ->and($this->pricing->tierForClient($detaillant))->toBe('detaillant')
        ->and($this->pricing->tierForClient(null))->toBe('detaillant'); // comptoir
});

test('le prix d\'un article suit le palier, avec repli sur standard', function () {
    expect($this->pricing->priceForStock($this->stock, 'grossiste'))->toBe(4000.0)
        ->and($this->pricing->priceForStock($this->stock, 'detaillant'))->toBe(5500.0)
        ->and($this->pricing->priceForStock($this->stock, 'standard'))->toBe(5000.0);

    // Palier sans tarif dédié → repli sur le standard (cf. PriceList::getPrice).
    $this->stock->priceList ?? null;
    expect($this->pricing->priceForStock($this->stock, 'inexistant'))->toBe(5000.0);
});

test('le POS expose les prix par palier et le palier de chaque client', function () {
    Client::create(['farm_id' => $this->farm->id, 'client_id' => 'C3', 'name' => 'Grossiste X', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0]);

    $resp = $this->actingAs($this->adminUser)->get(route('pos.index'))->assertOk();

    $product = collect($resp->viewData('products'))->firstWhere('id', $this->stock->id);
    expect($product['price'])->toBe(5500.0)              // affiché par défaut = détaillant
        ->and($product['prices']['grossiste'])->toBe(4000.0)
        ->and($product['prices']['standard'])->toBe(5000.0);

    $client = collect($resp->viewData('clients'))->firstWhere('name', 'Grossiste X');
    expect($client['tier'])->toBe('grossiste');
});
