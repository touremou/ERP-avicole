<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\ProductionType;
use App\Models\Setting;
use App\Models\Species;
use Database\Seeders\ProductionNormSeeder;
use Database\Seeders\SpeciesSeeder;
use Illuminate\Support\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SEULE DURÉE DE CYCLE PAR BANDE.
 *
 * Depuis #408, la fin d'une bande suit la durée de sa SOUCHE. Mais la fiche
 * (barre d'avancement), la liste des bandes, la phase d'aliment présélectionnée
 * au pointage et le rapport piscicole recalculaient chacun la leur, sur le seul
 * type de production. Un poulet local Cou Nu (112 j) au jour 60 :
 *
 *   • fin prévisionnelle ........ jour 112
 *   • barre d'avancement ........ 100 % (60 / 45)
 *   • aliment présélectionné .... Finition (60 > 0,6 × 45)
 *
 * Batch::cycleDays() est désormais LA durée, lue partout.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(SpeciesSeeder::class);
    $this->seed(ProductionNormSeeder::class);
    $this->actingAs($this->adminUser);

    $poulet = Species::where('slug', 'poulet')->first();
    $this->bande = Batch::factory()->create([
        'building_id' => Building::factory()->create(['type' => 'chair', 'capacity' => 10_000, 'farm_id' => session('current_farm_id')])->id,
        'species_id' => $poulet->id,
        'production_type_id' => ProductionType::where('species_id', $poulet->id)->where('slug', 'chair')->value('id'),
        'model_name' => 'Poulet local Cou Nu',
        'arrival_date' => Carbon::today()->subDays(60), 'birth_date' => null,
        'status' => 'Actif',
    ]);
});

test('la durée de la bande est celle de sa souche — et sa fin la suit', function () {
    expect($this->bande->cycleDays())->toBe(112)
        ->and($this->bande->expected_end_date->toDateString())
        ->toBe(Carbon::today()->subDays(60)->addDays(112)->toDateString());
});

test('au jour 60 d’un Cou Nu, l’aliment présélectionné est la CROISSANCE, pas la finition', function () {
    // 112 j : croissance jusqu'à 0,6 × 112 = 67 j. Sur 45 j, ce serait la finition dès 27 j.
    expect($this->bande->feedPreselectPhase(60))->toContain('Croissance');
});

test('la fiche montre l’avancement sur la durée de la bande, pas 100 % au jour 45', function () {
    $html = $this->get(route('batches.show', $this->bande))->assertOk()->getContent();

    // ~60 / 112 ≈ 54 % (et non 100 % : 60 / 45).
    preg_match('/Avancement du Cycle de Vie.{0,1500}?style="width: ([0-9.]+)%"/s', $html, $m);

    expect((float) ($m[1] ?? -1))->toBeGreaterThan(50.0)->toBeLessThan(60.0);
});

test('le réglage piscicole par espèce fixe aussi la FIN de la bande, plus seulement le rapport', function () {
    Setting::set('pisciculture.cycle_tilapia', 150);
    $tilapia = Species::where('slug', 'tilapia')->first();

    $poissons = Batch::factory()->create([
        'building_id' => Building::factory()->create(['type' => 'bassin', 'capacity' => 10_000, 'farm_id' => session('current_farm_id')])->id,
        'species_id' => $tilapia->id,
        'production_type_id' => ProductionType::where('species_id', $tilapia->id)->value('id'),
        'model_name' => null,
        'arrival_date' => Carbon::today(), 'birth_date' => null,
    ]);

    expect($poissons->cycleDays())->toBe(150)
        ->and($poissons->expected_end_date->toDateString())->toBe(Carbon::today()->addDays(150)->toDateString());
});
