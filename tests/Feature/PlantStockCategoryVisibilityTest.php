<?php

use App\Models\Setting;
use App\Models\Stock;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

test('catégories végétales visibles par défaut (paramètre vide → fallback toutes catégories)', function () {
    // Aucun paramètre stocks.categories configuré.
    $cats = Stock::activeCategories();

    expect($cats)->toHaveKey(Stock::CAT_RECOLTES)
        ->and($cats)->toHaveKey(Stock::CAT_INTRANTS);
});

test('la migration ajoute les catégories végétales à une liste personnalisée', function () {
    // Simule une install ayant restreint la liste affichée.
    Setting::set('stocks.categories', 'oeufs,conso');

    // Rejoue la logique de la migration de surfaçage.
    (require database_path('migrations/2026_06_20_000007_add_plant_stock_categories_to_setting.php'))->up();
    Setting::clearCache();

    $cats = Stock::activeCategories();

    expect($cats)->toHaveKey('oeufs')
        ->and($cats)->toHaveKey('conso')
        ->and($cats)->toHaveKey(Stock::CAT_RECOLTES)
        ->and($cats)->toHaveKey(Stock::CAT_INTRANTS);
});
