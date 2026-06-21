<?php

use App\Models\CropSpecies;
use App\Models\Farm;
use App\Models\Plot;
use Database\Seeders\CropCatalogueSeeder;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('le seeder renseigne les données de référence agronomiques du Riz', function () {
    (new CropCatalogueSeeder())->run();

    $riz = CropSpecies::where('name', 'Riz')->firstOrFail();

    expect($riz->sowing_months)->not->toBeEmpty();
    expect($riz->water_need)->toBe('eleve');
    expect($riz->agro_zones)->toContain('basse_guinee');
});

test('zoneFromRegion mappe correctement les régions guinéennes', function () {
    expect(Plot::zoneFromRegion('Kindia'))->toBe('basse_guinee');
    expect(Plot::zoneFromRegion('Labé'))->toBe('moyenne_guinee');
    expect(Plot::zoneFromRegion('Nzérékoré'))->toBe('guinee_forestiere');
    expect(Plot::zoneFromRegion('Kankan'))->toBe('haute_guinee');
    expect(Plot::zoneFromRegion('Inconnue'))->toBeNull();
    expect(Plot::zoneFromRegion(null))->toBeNull();
});

test('resolvedAgroZone privilégie la zone explicite sinon hérite de la ferme', function () {
    $this->farm->update(['region' => 'Labé']);

    // Sans zone explicite : héritée de la région de la ferme.
    $plot = Plot::create([
        'farm_id' => $this->farm->id,
        'name'    => 'Parcelle A',
        'area_ha' => 1.0,
        'status'  => Plot::STATUS_DISPONIBLE,
    ]);
    expect($plot->resolvedAgroZone())->toBe('moyenne_guinee');

    // Avec zone explicite : prioritaire.
    $plot2 = Plot::create([
        'farm_id'   => $this->farm->id,
        'name'      => 'Parcelle B',
        'area_ha'   => 1.0,
        'status'    => Plot::STATUS_DISPONIBLE,
        'agro_zone' => 'guinee_forestiere',
    ]);
    expect($plot2->resolvedAgroZone())->toBe('guinee_forestiere');
});

test('l\'accesseur de fenêtre de semis rend une plage de mois en français', function () {
    $species = new CropSpecies(['sowing_months' => [5, 6, 7]]);

    expect($species->sowing_label)->toBe('Mai – Juil.');
});

test('un manager peut renseigner la zone agro-écologique à la création et la modification', function () {
    $this->actingAs($this->managerUser)
        ->post(route('plots.store'), [
            'name'      => 'Parcelle Zone',
            'area_ha'   => 1.5,
            'agro_zone' => 'basse_guinee',
        ])
        ->assertRedirect();

    $plot = Plot::where('name', 'Parcelle Zone')->firstOrFail();
    expect($plot->agro_zone)->toBe('basse_guinee');

    $this->actingAs($this->managerUser)
        ->put(route('plots.update', $plot), [
            'name'      => 'Parcelle Zone',
            'area_ha'   => 1.5,
            'status'    => Plot::STATUS_DISPONIBLE,
            'agro_zone' => 'haute_guinee',
        ])
        ->assertRedirect();

    expect($plot->fresh()->agro_zone)->toBe('haute_guinee');
});
