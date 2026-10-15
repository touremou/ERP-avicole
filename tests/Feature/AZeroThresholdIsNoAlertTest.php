<?php

use App\Models\RawMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SEUIL À ZÉRO N'EST PAS UN SEUIL — POUR LES MATIÈRES PREMIÈRES AUSSI.
 *
 * Le magasin applique déjà la règle (Stock::is_low) partout. La provenderie
 * l'appliquait sur la fiche matière (is_low_stock) mais pas sur son tableau de
 * bord ni sur sa liste : toute matière épuisée SANS seuil réglé y apparaissait
 * en rupture, et la liste des aliments finis faisait de même.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('une matière épuisée sans seuil n’est pas en rupture ; avec seuil, elle l’est', function () {
    $sansSeuil = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Sans seuil', 'unit' => 'kg',
        'stock_qty' => 0, 'unit_cost' => 1, 'alert_threshold' => 0, 'is_active' => true]);
    $sousSeuil = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Sous seuil', 'unit' => 'kg',
        'stock_qty' => 10, 'unit_cost' => 1, 'alert_threshold' => 100, 'is_active' => true]);

    expect(RawMaterial::critical()->pluck('name')->all())->toBe(['Sous seuil'])
        ->and($sansSeuil->is_low_stock)->toBeFalse();

    $alertes = $this->get(route('provenderie.dashboard'))->assertOk()->viewData('lowStockAlerts');
    expect($alertes->pluck('name')->all())->toBe(['Sous seuil']);
});
