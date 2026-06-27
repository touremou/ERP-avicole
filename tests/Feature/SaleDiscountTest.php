<?php

use App\Models\Sale;
use App\Models\SaleItem;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function saleWith(array $attrs = [], float $lineTotal = 10000): Sale
{
    $client = \App\Models\Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => 'CLI-' . fake()->unique()->numerify('###'),
        'name' => 'Client', 'type' => 'particulier', 'category' => 'detaillant',
    ]);
    $sale = Sale::create(array_merge([
        'farm_id' => session('current_farm_id'), 'reference' => 'BL-' . fake()->unique()->numerify('#####'),
        'client_id' => $client->id, 'user_id' => \App\Models\User::value('id'), 'sale_date' => now(),
        'type' => 'bon_livraison', 'status' => 'brouillon', 'tax_rate' => 0,
    ], $attrs));
    SaleItem::create([
        'farm_id' => session('current_farm_id'), 'sale_id' => $sale->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs', 'quantity' => 1, 'unit' => 'alveole', 'unit_price' => $lineTotal, 'total' => $lineTotal,
    ]);
    return $sale;
}

test('une remise en pourcentage réduit le total', function () {
    $sale = saleWith(['discount_type' => 'percent', 'discount_value' => 10]); // 10% de 10000
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->discount_amount)->toBe(1000.0)
        ->and((float) $sale->total_amount)->toBe(9000.0);
});

test('une remise en montant fixe est appliquée', function () {
    $sale = saleWith(['discount_type' => 'amount', 'discount_value' => 2500]);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->discount_amount)->toBe(2500.0)
        ->and((float) $sale->total_amount)->toBe(7500.0);
});

test('la remise ne peut pas dépasser le sous-total', function () {
    $sale = saleWith(['discount_type' => 'amount', 'discount_value' => 99999]);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->discount_amount)->toBe(10000.0)
        ->and((float) $sale->total_amount)->toBe(0.0);
});

test('la TVA porte sur la base après remise', function () {
    // 10000 − 10% = 9000 ; TVA 18% = 1620 ; total = 10620
    $sale = saleWith(['discount_type' => 'percent', 'discount_value' => 10, 'tax_rate' => 18, 'type' => 'facture']);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->discount_amount)->toBe(1000.0)
        ->and((float) $sale->tax_amount)->toBe(1620.0)
        ->and((float) $sale->total_amount)->toBe(10620.0);
});

test('un pourcentage > 100 est plafonné à 100', function () {
    $sale = saleWith(['discount_type' => 'percent', 'discount_value' => 150]);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->total_amount)->toBe(0.0);
});

test('sans remise, le total reste le sous-total', function () {
    $sale = saleWith(['discount_type' => 'none', 'discount_value' => 0]);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->discount_amount)->toBe(0.0)
        ->and((float) $sale->total_amount)->toBe(10000.0);
});
