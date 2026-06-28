<?php

use App\Models\DispatchItem;
use App\Models\SaleItem;
use App\Models\Stock;

// Les libellés affichés dérivent d'une source canonique (plus de slug brut).

test('SaleItem expose le libellé canonique du type (jamais le slug brut)', function () {
    expect((new SaleItem(['product_type' => 'produits_finis']))->type_label)->toBe('Produits finis')
        ->and((new SaleItem(['product_type' => 'animal_vif']))->type_label)->toBe('Animal vivant')
        ->and((new SaleItem(['product_type' => 'volaille_vivante']))->type_label)->toBe('Volaille vivante'); // legacy
});

test('DispatchItem partage la même terminologie que SaleItem', function () {
    expect((new DispatchItem(['product_type' => 'produits_finis']))->type_label)->toBe('Produits finis')
        ->and((new DispatchItem(['product_type' => 'recoltes']))->type_label)->toBe('Récoltes');
});

test('Stock expose le libellé canonique de la catégorie', function () {
    expect((new Stock(['category' => Stock::CAT_CONSO]))->category_label)->toBe('Aliment & Santé')
        ->and((new Stock(['category' => Stock::CAT_LITIERES]))->category_label)->toBe('Litières')
        ->and((new Stock(['category' => Stock::CAT_PRODUITS_FINIS]))->category_label)->toBe('Produits Finis');
});
