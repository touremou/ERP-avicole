<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Species;
use App\Models\Stock;
use App\Services\UnitConverter;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES FACTEURS DE CONVERSION VIENNENT DU PARAMÉTRAGE.
 *
 * `UnitConverter` s'annonce « Source de vérité UNIQUE des conversions d'unités
 * métier » et son commentaire dit que les facteurs « ÉTAIENT réécrits en dur dans
 * une dizaine d'endroits » — au passé.
 *
 * Ils l'étaient encore dans HUIT : un contrôleur et six vues divisaient par 50 ou
 * multipliaient par 30 à la main.
 *
 * Ce n'est pas cosmétique. En Guinée l'aliment s'achète aussi en sacs de 25 kg, et
 * `general.feed_bag_weight` existe précisément pour ça. Une ferme qui l'aurait
 * réglé à 25 aurait vu :
 *
 *   • le tableau de bord provenderie, les cartes de stock, la fiche article et le
 *     journal de production annoncer DEUX FOIS MOINS de sacs qu'elle n'en a ;
 *   • l'écran de lancement d'une fabrication vérifier les stocks contre un poids
 *     double du réel, donc annoncer des manques ou des disponibilités faux ;
 *   • la fiche du lot borner la saisie de consommation d'aliment sur un plafond
 *     deux fois trop haut.
 */

beforeEach(function () {
    $this->setUpRbac();
    Setting::clearCache();
});

test('les facteurs par défaut sont ceux du paramétrage livré', function () {
    expect(UnitConverter::bagWeight())->toBe(50.0)
        ->and(UnitConverter::eggsPerTray())->toBe(30);
});

test('changer le poids du sac change TOUS les écrans', function () {
    // Le cas concret : une ferme qui achète en sacs de 25 kg.
    Setting::set('general.feed_bag_weight', 25);
    Setting::clearCache();

    $stock = Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => 'Chair Croissance',
        'unit' => 'KG', 'current_quantity' => 500, 'unit_price' => 5000,
        'last_unit_price' => 5000, 'alert_threshold' => 50,
    ]);

    // 500 kg = 20 sacs de 25 kg, et non 10.
    expect(UnitConverter::kgToSacks(500))->toBe(20.0);

    $this->actingAs($this->adminUser)->get(route('stocks.show', $stock))
        ->assertOk()
        // La vue rend le séparateur décimal par défaut de number_format ; ce n'est
        // pas l'objet de ce test, seul le NOMBRE de sacs compte.
        ->assertSee('20.0', false)
        ->assertSee('Sacs de 25 kg');
});

test('la disponibilité aliment d’un lot suit le poids du sac paramétré', function () {
    // Ce plafond borne ce que le technicien peut déclarer avoir consommé : un
    // facteur faux l'autorise à saisir le double.
    Setting::set('general.feed_bag_weight', 25);
    Setting::clearCache();

    $species = Species::firstOrCreate(['slug' => 'poulet'], ['name_fr' => 'Poulet', 'is_active' => true]);
    $batch = Batch::factory()->create([
        'farm_id'            => $this->farm->id,
        'building_id'        => Building::factory()->create(['farm_id' => $this->farm->id])->id,
        'species_id'         => $species->id,
        'production_type_id' => ProductionType::resolveOrCreate('chair', $species->id)->id,
        'status'             => 'Actif', 'arrival_date' => now()->subDays(10)->toDateString(),
        'initial_quantity'   => 500, 'current_quantity' => 500,
    ]);

    Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => 'Chair Démarrage',
        'unit' => 'Sac', 'current_quantity' => 10, 'unit_price' => 250000,
        'last_unit_price' => 250000, 'alert_threshold' => 2,
    ]);

    $response = $this->actingAs($this->adminUser)->get(route('batches.show', $batch))->assertOk();
    $feedStocks = collect($response->viewData('feedStocks'));
    $sacStock = $feedStocks->firstWhere('is_sac', true);

    // 10 sacs de 25 kg = 250 kg. Le « × 50 » codé en dur annonçait 500.
    expect($sacStock)->not->toBeNull()
        ->and($sacStock['available_kg'])->toBe(250.0);
});

test('le nombre d’œufs par alvéole vient aussi du paramétrage', function () {
    // Les plateaux de 24 existent : le « × 30 » codé en dur de la carte de stock
    // et de la fiche de ponte s'en moquait.
    Setting::set('general.eggs_per_tray', 24);
    Setting::clearCache();

    expect(UnitConverter::eggsPerTray())->toBe(24)
        ->and(UnitConverter::traysToEggs(2.5))->toBe(60)
        ->and(UnitConverter::eggsToTrays(120))->toBe(5.0);
});

test('aucune vue ne recopie plus un facteur de conversion', function () {
    // Le garde-fou : c'est cette recopie que le commentaire de UnitConverter
    // affirmait — à tort — avoir supprimée.
    $files = array_merge(
        glob(resource_path('views/stocks/**/*.blade.php')),
        glob(resource_path('views/stocks/*.blade.php')),
        glob(resource_path('views/provenderie/**/*.blade.php')),
        glob(resource_path('views/egg-productions/*.blade.php')),
        [app_path('Http/Controllers/BatchController.php')],
    );

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach (['/ 50,', '/ 50)', '* 50;', '* 50,', ') * 30)', '/ 30,'] as $pattern) {
            expect($source)->not->toContain(
                $pattern,
                // Pest traite un 2e argument comme une autre valeur attendue :
                // le contexte reste donc en commentaire. Fichier : {$file}
            );
        }
    }
});

test('l’écran de fabrication reçoit le poids du sac, il ne le devine pas', function () {
    Setting::set('general.feed_bag_weight', 25);
    Setting::clearCache();

    $this->actingAs($this->adminUser)->get(route('production.create'))
        ->assertOk()
        ->assertSee('const BAG_WEIGHT_KG = 25', false);
});
