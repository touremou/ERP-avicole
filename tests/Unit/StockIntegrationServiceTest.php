<?php

use App\Models\Farm;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockIntegrationService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // StockMovement référence user_id (Auth::id() ?? 1) et farm_id (session) :
    // sans utilisateur authentifié ni ferme courante, l'insertion violait les
    // contraintes. On pose donc le contexte minimal.
    $farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
    test()->actingAs(User::factory()->create());
});

test('B-16 : recherche exacte trouve le bon article', function () {
    Stock::factory()->create(['item_name' => 'Ponte Démarrage (Poussin)', 'category' => 'conso', 'current_quantity' => 100]);
    Stock::factory()->create(['item_name' => 'Ponte Croissance (Poulette)', 'category' => 'conso', 'current_quantity' => 200]);

    StockIntegrationService::syncMovement('Ponte Démarrage (Poussin)', 'conso', 50, 'in', 'Test exact', 'KG');

    $stock = Stock::where('item_name', 'Ponte Démarrage (Poussin)')->first();
    expect((float) $stock->current_quantity)->toBe(150.0);

    $other = Stock::where('item_name', 'Ponte Croissance (Poulette)')->first();
    expect((float) $other->current_quantity)->toBe(200.0);
});

test('B-16 : retourne false si article introuvable', function () {
    $result = StockIntegrationService::syncMovement('Inexistant', 'conso', 50, 'in', 'Test', 'KG');
    expect($result)->toBeFalse();
});

test('conversion Sac→KG (1 Sac = 50 KG)', function () {
    $stock = Stock::factory()->create([
        'item_name' => 'Chair Croissance', 'category' => 'conso',
        'current_quantity' => 0, 'unit' => 'KG',
    ]);

    StockIntegrationService::syncMovement('Chair Croissance', 'conso', 3, 'in', 'Test sac', 'Sac');

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(150.0);
});

test('pas de conversion si unité = KG', function () {
    $stock = Stock::factory()->create([
        'item_name' => 'Chair Finition', 'category' => 'conso',
        'current_quantity' => 0, 'unit' => 'KG',
    ]);

    StockIntegrationService::syncMovement('Chair Finition', 'conso', 75, 'in', 'Test kg', 'KG');

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(75.0);
});

test('conversion Unité→Alvéole pour oeufs', function () {
    $stock = Stock::factory()->create([
        'item_name' => 'M', 'category' => 'oeufs',
        'current_quantity' => 0, 'unit' => 'Alvéole',
    ]);

    StockIntegrationService::syncMovement('M', 'oeufs', 90, 'in', 'Test', 'Unité');

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(3.0);
});

test('sortie ne descend pas sous zéro', function () {
    $stock = Stock::factory()->create([
        'item_name' => 'L', 'category' => 'oeufs',
        'current_quantity' => 5, 'unit' => 'Alvéole',
    ]);

    StockIntegrationService::syncMovement('L', 'oeufs', 100, 'out', 'Sortie massive', 'Alvéole');

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(0.0);
});

test('ajustement écrase la quantité', function () {
    $stock = Stock::factory()->create([
        'item_name' => 'XL', 'category' => 'oeufs',
        'current_quantity' => 999, 'unit' => 'Alvéole',
    ]);

    StockIntegrationService::syncMovement('XL', 'oeufs', 42, 'adjustment', 'Inventaire', 'Alvéole');

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(42.0);
});

test('sync() est un alias de syncMovement()', function () {
    $stock = Stock::factory()->create([
        'item_name' => 'S', 'category' => 'oeufs',
        'current_quantity' => 10, 'unit' => 'Alvéole',
    ]);

    $result = StockIntegrationService::sync('S', 'oeufs', 5, 'in', 'Via alias', 'Alvéole');

    expect($result)->toBeInstanceOf(StockMovement::class);
    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(15.0);
});
