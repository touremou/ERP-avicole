<?php

use App\Models\Product;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function stockItem(array $attrs = []): Stock
{
    return Stock::create(array_merge([
        'farm_id' => session('current_farm_id'), 'item_name' => 'Calibre L', 'category' => Stock::CAT_OEUFS,
        'unit' => 'Alvéole', 'current_quantity' => 120, 'alert_threshold' => 10,
    ], $attrs));
}

test('un article lié expose la disponibilité de son stock', function () {
    $stock = stockItem(['current_quantity' => 75]);
    $product = Product::create([
        'farm_id' => session('current_farm_id'), 'name' => 'Œuf L', 'product_type' => 'oeufs',
        'stock_id' => $stock->id, 'unit' => 'Alvéole', 'base_price' => 3000, 'is_active' => true,
    ]);

    expect($product->available_quantity)->toBe(75.0);
});

test('un article non lié n\'a pas de disponibilité (null)', function () {
    $product = Product::create([
        'farm_id' => session('current_farm_id'), 'name' => 'Service', 'product_type' => 'autre',
        'unit' => 'unite', 'base_price' => 5000, 'is_active' => true,
    ]);

    expect($product->available_quantity)->toBeNull();
});

test('créer un article avec un stock lié enregistre le lien', function () {
    $stock = stockItem();

    $this->actingAs($this->adminUser)->post(route('products.store'), [
        'name' => 'Œuf calibre L', 'product_type' => 'oeufs', 'stock_id' => $stock->id,
        'unit' => 'Alvéole', 'base_price' => 3000, 'is_active' => 1,
    ])->assertRedirect();

    expect(Product::where('name', 'Œuf calibre L')->value('stock_id'))->toBe($stock->id);
});

test('le formulaire de vente transmet stock_id et disponibilité du catalogue', function () {
    $stock = stockItem(['current_quantity' => 200]);
    Product::create([
        'farm_id' => session('current_farm_id'), 'name' => 'Œuf L', 'product_type' => 'oeufs',
        'stock_id' => $stock->id, 'unit' => 'Alvéole', 'base_price' => 3000, 'is_active' => true,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('sales.create'))
        ->assertOk()
        ->assertViewHas('catalog', fn ($c) => $c->contains(fn ($a) => $a['stock_id'] === $stock->id && $a['available'] === 200.0));
});
