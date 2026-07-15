<?php

use App\Actions\Stock\UpdateStockAction;
use App\Models\Stock;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
    $this->user = User::factory()->create();
});

test('la valorisation du stock (total_value) repose sur le CMP last_unit_price', function () {
    $stock = Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => 'Maïs', 'unit' => 'KG',
        'current_quantity' => 100, 'unit_price' => 100, 'last_unit_price' => 150, 'alert_threshold' => 0,
    ]);

    // total_value = quantité × CMP (last_unit_price), pas × unit_price.
    expect((float) $stock->total_value)->toBe(15000.0);
});

test('une correction manuelle du prix aligne unit_price ET last_unit_price (cohérence valorisation)', function () {
    $stock = Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => 'Maïs', 'unit' => 'KG',
        'current_quantity' => 100, 'unit_price' => 100, 'last_unit_price' => 150, 'alert_threshold' => 0,
    ]);

    app(UpdateStockAction::class)->execute($stock, [
        'item_name'        => 'Maïs',
        'unit'             => 'KG',
        'alert_threshold'  => 0,
        'current_quantity' => 100,
        'unit_price'       => 200,
    ], $this->user->id);

    $fresh = $stock->fresh();
    // Les deux prix sont alignés → la valorisation (total_value) suit la correction.
    expect((float) $fresh->unit_price)->toBe(200.0)
        ->and((float) $fresh->last_unit_price)->toBe(200.0)
        ->and((float) $fresh->total_value)->toBe(20000.0);
});
