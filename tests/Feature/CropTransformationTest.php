<?php

use App\Models\CropTransformation;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('un opérateur (C) enregistre une transformation et le rendement est calculé', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('crop-transformations.store'), [
            'input_product'       => 'Manioc',
            'output_product'      => 'Gari',
            'transformation_type' => 'sechage',
            'input_quantity'      => 1000,
            'output_quantity'     => 250,
            'production_date'     => now()->toDateString(),
        ])
        ->assertRedirect(route('crop-transformations.index'));

    $t = CropTransformation::first();
    expect($t)->not->toBeNull()
        ->and((float) $t->yield_percent)->toBe(25.0)
        ->and($t->batch_number)->toStartWith('TRV-');
});

test('un lecteur seul (L) ne peut pas enregistrer de transformation', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('crop-transformations.store'), [
            'input_product'       => 'Mangue',
            'output_product'      => 'Jus',
            'transformation_type' => 'jus',
            'input_quantity'      => 100,
            'output_quantity'     => 60,
            'production_date'     => now()->toDateString(),
        ])
        ->assertSessionHas('error');

    expect(CropTransformation::count())->toBe(0);
});

test('la transformation peut alimenter le stock de produits finis', function () {
    $this->actingAs($this->managerUser)
        ->post(route('crop-transformations.store'), [
            'input_product'       => 'Maïs',
            'output_product'      => 'Farine de maïs',
            'transformation_type' => 'mouture',
            'input_quantity'      => 500,
            'output_quantity'     => 450,
            'output_unit_price'   => 8000,
            'production_date'     => now()->toDateString(),
            'synced_to_stock'     => 1,
            'output_stock_item'   => 'Farine de maïs',
        ])
        ->assertRedirect();

    $stock = Stock::where('item_name', 'Farine de maïs')
        ->where('category', Stock::CAT_PRODUITS_FINIS)
        ->first();

    expect($stock)->not->toBeNull()
        ->and((float) $stock->current_quantity)->toBe(450.0)
        ->and(CropTransformation::first()->synced_to_stock)->toBeTrue();
});

test('le déstockage de l\'intrant retire la quantité du stock récoltes', function () {
    // Stock initial de récolte.
    Stock::create([
        'category'         => Stock::CAT_RECOLTES,
        'item_name'        => 'Manioc frais',
        'unit'             => 'kg',
        'current_quantity' => 2000,
        'unit_price'       => 1000,
        'last_unit_price'  => 1000,
        'alert_threshold'  => 0,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('crop-transformations.store'), [
            'input_product'       => 'Manioc',
            'output_product'      => 'Gari',
            'transformation_type' => 'sechage',
            'input_quantity'      => 800,
            'output_quantity'     => 200,
            'production_date'     => now()->toDateString(),
            'consumed_from_stock' => 1,
            'input_stock_item'    => 'Manioc frais',
        ])
        ->assertRedirect();

    $stock = Stock::where('item_name', 'Manioc frais')->where('category', Stock::CAT_RECOLTES)->first();

    expect((float) $stock->current_quantity)->toBe(1200.0) // 2000 - 800
        ->and(CropTransformation::first()->consumed_from_stock)->toBeTrue();
});
