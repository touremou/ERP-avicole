<?php

use App\Models\Stock;

uses(Tests\TestCase::class);

test('les clés de CATEGORY_META correspondent exactement aux slugs constants', function () {
    expect(array_keys(Stock::CATEGORY_META))->toBe([
        Stock::CAT_OEUFS,
        Stock::CAT_LAIT,
        Stock::CAT_CONSO,
        Stock::CAT_PRODUITS_FINIS,
        Stock::CAT_LITIERES,
        Stock::CAT_MATERIELS,
    ]);
});

test('les valeurs des slugs constants restent stables (valeurs stockées en base)', function () {
    // Garde-fou : un renommage involontaire casserait les enregistrements
    // existants en base, dont la colonne `category` contient ces littéraux.
    expect(Stock::CAT_OEUFS)->toBe('oeufs')
        ->and(Stock::CAT_LAIT)->toBe('lait')
        ->and(Stock::CAT_CONSO)->toBe('conso')
        ->and(Stock::CAT_PRODUITS_FINIS)->toBe('produits_finis')
        ->and(Stock::CAT_LITIERES)->toBe('litieres')
        ->and(Stock::CAT_MATERIELS)->toBe('materiels');
});

test('categoryForProductType mappe les types stockés et retombe sur materiels', function () {
    expect(Stock::categoryForProductType('oeufs'))->toBe(Stock::CAT_OEUFS)
        ->and(Stock::categoryForProductType('lait'))->toBe(Stock::CAT_LAIT)
        ->and(Stock::categoryForProductType('aliment'))->toBe(Stock::CAT_CONSO)
        ->and(Stock::categoryForProductType('produits_finis'))->toBe(Stock::CAT_PRODUITS_FINIS)
        ->and(Stock::categoryForProductType('materiel'))->toBe(Stock::CAT_MATERIELS)
        // product_type inconnu / non adossé au magasin → repli historique
        ->and(Stock::categoryForProductType('autre'))->toBe(Stock::CAT_MATERIELS);
});

test('toutes les cibles de PRODUCT_TYPE_TO_CATEGORY sont des catégories connues', function () {
    $known = array_keys(Stock::CATEGORY_META);
    foreach (Stock::PRODUCT_TYPE_TO_CATEGORY as $category) {
        expect($known)->toContain($category);
    }
});
