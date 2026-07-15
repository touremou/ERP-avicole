<?php

use App\Models\Setting;
use App\Models\Stock;
use App\Services\UnitConverter;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Facteurs par défaut ──────────────────────────────────────────────────────

test('le poids du sac par défaut est 50 kg', function () {
    expect(UnitConverter::bagWeight())->toBe(50.0);
});

test('le nombre d\'œufs par alvéole par défaut est 30', function () {
    expect(UnitConverter::eggsPerTray())->toBe(30);
});

// ─── Conversions aliment ───────────────────────────────────────────────────────

test('sac vers kg utilise le poids du sac', function () {
    expect(UnitConverter::sacksToKg(10))->toBe(500.0);
});

test('kg vers sac est l\'inverse', function () {
    expect(UnitConverter::kgToSacks(500))->toBe(10.0);
});

test('une surcharge de poids du sac est prioritaire sur le paramètre', function () {
    // Sac de 25 kg (ex. achat spécifique) → 4 sacs = 100 kg
    expect(UnitConverter::sacksToKg(4, 25))->toBe(100.0);
});

// ─── Conversions œufs ──────────────────────────────────────────────────────────

test('alvéoles vers œufs utilise le facteur configuré', function () {
    expect(UnitConverter::traysToEggs(2))->toBe(60);
});

test('œufs vers alvéoles est fractionnaire', function () {
    expect(UnitConverter::eggsToTrays(45))->toBe(1.5);
});

// ─── Normalisation vers l'unité pivot ──────────────────────────────────────────

test('toStockBase convertit un sac d\'aliment en kg', function () {
    expect(UnitConverter::toStockBase(3, 'Sac', Stock::CAT_CONSO))->toBe(150.0);
});

test('toStockBase laisse les kg d\'aliment inchangés', function () {
    expect(UnitConverter::toStockBase(120, 'KG', Stock::CAT_CONSO))->toBe(120.0);
});

test('toStockBase convertit des œufs en unités vers alvéoles', function () {
    expect(UnitConverter::toStockBase(90, 'Unité', Stock::CAT_OEUFS))->toBe(3.0);
});

test('toStockBase ne convertit pas les catégories sans unité pivot', function () {
    expect(UnitConverter::toStockBase(7, 'Unité', Stock::CAT_MATERIELS))->toBe(7.0);
});

// ─── Les facteurs suivent le paramétrage de la ferme ───────────────────────────

test('un poids de sac reparamétré change toutes les conversions aliment', function () {
    Setting::set('general.feed_bag_weight', 25);

    expect(UnitConverter::bagWeight())->toBe(25.0)
        ->and(UnitConverter::sacksToKg(4))->toBe(100.0)
        ->and(UnitConverter::toStockBase(4, 'Sac', Stock::CAT_CONSO))->toBe(100.0);
});

test('un nombre d\'œufs par alvéole reparamétré change les conversions œufs', function () {
    Setting::set('general.eggs_per_tray', 36);

    expect(UnitConverter::eggsPerTray())->toBe(36)
        ->and(UnitConverter::traysToEggs(2))->toBe(72);
});
