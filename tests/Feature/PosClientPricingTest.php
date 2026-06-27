<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\SalePriceList;
use App\Models\SalePriceListItem;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function posCatalogProduct(float $base = 5000): Product
{
    $stock = Stock::create([
        'farm_id' => session('current_farm_id'), 'category' => Stock::CAT_PRODUITS_FINIS,
        'item_name' => 'Poulet', 'unit' => 'piece', 'current_quantity' => 100, 'alert_threshold' => 5,
    ]);
    return Product::create([
        'farm_id' => session('current_farm_id'), 'name' => 'Poulet entier', 'product_type' => 'produits_finis',
        'stock_id' => $stock->id, 'unit' => 'piece', 'base_price' => $base, 'is_active' => true,
    ]);
}

test('catalog-prices renvoie le tarif du client par article', function () {
    $product = posCatalogProduct(5000);
    $gros = SalePriceList::create(['farm_id' => session('current_farm_id'), 'name' => 'Grossiste', 'is_default' => false]);
    SalePriceListItem::create(['sale_price_list_id' => $gros->id, 'product_id' => $product->id, 'product_type' => 'produits_finis', 'unit_price' => 4000]);
    $client = Client::create(['farm_id' => session('current_farm_id'), 'client_id' => 'CLI-G', 'name' => 'Gros', 'type' => 'entreprise', 'category' => 'grossiste', 'price_list_id' => $gros->id]);

    $this->actingAs($this->adminUser)
        ->getJson(route('sales.catalog-prices', ['client_id' => $client->id]))
        ->assertOk()
        ->assertJson(['prices' => [$product->id => 4000]]);
});

test('sans client, catalog-prices renvoie le prix par défaut/de base', function () {
    $product = posCatalogProduct(5000);

    $this->actingAs($this->adminUser)
        ->getJson(route('sales.catalog-prices'))
        ->assertOk()
        ->assertJson(['prices' => [$product->id => 5000]]);
});
