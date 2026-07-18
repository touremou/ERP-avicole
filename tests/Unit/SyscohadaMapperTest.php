<?php

use App\Models\Expense;
use App\Services\Accounting\SyscohadaMapper;

/*
 * Mapping SYSCOHADA : chaque libellé de charge/produit du P&L doit tomber sur
 * un compte de la bonne classe, et le regroupement doit sommer/trier juste.
 */

beforeEach(function () {
    $this->mapper = new SyscohadaMapper();
});

test('les charges fixes du P&L sont rattachées à la bonne classe SYSCOHADA', function () {
    expect($this->mapper->chargeAccount('Aliment')[0])->toBe('602')
        ->and($this->mapper->chargeAccount('Achats animaux (lots)')[0])->toBe('602')
        ->and($this->mapper->chargeAccount('Santé / prophylaxie')[0])->toBe('604')
        ->and($this->mapper->chargeAccount('Eau')[0])->toBe('605')
        ->and($this->mapper->chargeAccount('Carburant')[0])->toBe('605')
        ->and($this->mapper->chargeAccount("Main d'œuvre (paie)")[0])->toBe('661');
});

test('les charges du registre des dépenses sont mappées par catégorie', function () {
    // Libellé tel que produit par le contrôleur : « Dépenses : {Catégorie} ».
    $transport = 'Dépenses : ' . Expense::CATEGORIES['transport'];
    $taxes     = 'Dépenses : ' . Expense::CATEGORIES['taxes'];
    $location  = 'Dépenses : ' . Expense::CATEGORIES['location'];

    expect($this->mapper->chargeAccount($transport)[0])->toBe('612')   // classe 61 Transports
        ->and($this->mapper->chargeAccount($taxes)[0])->toBe('646')    // classe 64 Impôts et taxes
        ->and($this->mapper->chargeAccount($location)[0])->toBe('622'); // classe 62 Services ext. A
});

test('une charge inconnue tombe sur le repli explicite (classe 60)', function () {
    expect($this->mapper->chargeAccount('Ligne totalement inconnue')[0])->toBe('608');
});

test('tous les produits d\'une ferme intégrée vont en 701 (Ventes)', function () {
    expect($this->mapper->produitAccount('Œufs')[0])->toBe('701')
        ->and($this->mapper->produitAccount('Lait (collecte valorisée)')[0])->toBe('701')
        ->and($this->mapper->produitAccount('Production végétale')[0])->toBe('701');
});

test('le regroupement somme par compte et par classe, et trie par numéro', function () {
    $costs = [
        'Aliment'                 => 1000.0,  // 602 → classe 60
        'Santé / prophylaxie'     => 500.0,   // 604 → classe 60
        "Main d'œuvre (paie)"     => 800.0,   // 661 → classe 66
        'Dépenses : ' . Expense::CATEGORIES['transport'] => 300.0, // 612 → classe 61
    ];

    $groups = $this->mapper->group($costs, 'charge');

    // Classes triées : 60, 61, 66.
    expect(array_column($groups, 'class'))->toBe(['60', '61', '66']);

    $classe60 = collect($groups)->firstWhere('class', '60');
    expect($classe60['total'])->toBe(1500.0)                        // 1000 + 500
        ->and(collect($classe60['accounts'])->pluck('account')->all())->toBe(['602', '604']);

    $classe66 = collect($groups)->firstWhere('class', '66');
    expect($classe66['total'])->toBe(800.0);
});

test('les lignes à montant nul sont ignorées dans le regroupement', function () {
    $groups = $this->mapper->group(['Eau' => 0.0, 'Aliment' => 200.0], 'charge');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['total'])->toBe(200.0);
});
