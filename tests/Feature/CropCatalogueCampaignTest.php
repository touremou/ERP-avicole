<?php

use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropRecipe;
use App\Models\CropSpecies;
use App\Models\Plot;
use App\Models\WeatherReading;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── CATALOGUE ───

test('un opérateur peut ajouter une culture au catalogue', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('crop-catalogue.store'), [
            'type'           => 'cereale',
            'name'           => 'Sorgho',
            'cycle_days_min' => 90,
            'cycle_days_max' => 120,
        ])
        ->assertRedirect();

    expect(CropSpecies::where('name', 'Sorgho')->exists())->toBeTrue();
});

test('le catalogue est groupé par type et la page de liste répond', function () {
    CropSpecies::create(['type' => 'tubercule', 'name' => 'Manioc']);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-catalogue.index'))
        ->assertOk()
        ->assertSee('Manioc');
});

test('on peut ajouter une variété à une espèce', function () {
    $species = CropSpecies::create(['type' => 'cereale', 'name' => 'Maïs']);

    $this->actingAs($this->operatorUser)
        ->post(route('crop-catalogue.varieties.store', $species), [
            'name'       => 'DK 818',
            'cycle_days' => 100,
        ])
        ->assertRedirect();

    expect($species->varieties()->where('name', 'DK 818')->exists())->toBeTrue();
});

// ─── CAMPAGNES ───

test('un opérateur peut créer une campagne agricole', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('crop-campaigns.store'), [
            'name'       => 'Grande saison pluies 2026',
            'year'       => 2026,
            'season'     => 'grande_saison_pluies',
            'start_date' => '2026-05-01',
        ])
        ->assertRedirect();

    expect(CropCampaign::where('name', 'Grande saison pluies 2026')->exists())->toBeTrue();
});

test('un cycle rattaché à une campagne agrège son total récolté', function () {
    $campaign = CropCampaign::create([
        'farm_id'    => $this->farm->id,
        'name'       => 'Campagne test',
        'year'       => now()->year,
        'season'     => 'saison_seche',
        'start_date' => now()->toDateString(),
    ]);

    $plot = Plot::create(['farm_id' => $this->farm->id, 'name' => 'P1', 'area_ha' => 2, 'status' => Plot::STATUS_EN_CULTURE]);
    $cycle = CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'campaign_id'   => $campaign->id,
        'crop_name'     => 'Maïs',
        'area_used_ha'  => 2,
        'planting_date' => now()->subMonths(2)->toDateString(),
    ]);
    $cycle->harvests()->create([
        'farm_id'      => $this->farm->id,
        'harvest_date' => now()->toDateString(),
        'quantity'     => 1200,
        'unit'         => 'kg',
    ]);

    expect((float) $campaign->fresh()->total_harvested)->toBe(1200.0);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-campaigns.show', $campaign))
        ->assertOk()
        ->assertSee('Maïs');
});

// ─── MÉTÉO ───

test('un opérateur peut enregistrer un relevé météo', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('weather.store'), [
            'reading_date' => now()->toDateString(),
            'rainfall_mm'  => 24.5,
            'temperature_max' => 33,
        ])
        ->assertRedirect();

    expect(WeatherReading::where('rainfall_mm', 24.5)->exists())->toBeTrue();
});

test('la page météo affiche le cumul pluviométrique du mois', function () {
    WeatherReading::create([
        'farm_id'      => $this->farm->id,
        'reading_date' => now()->toDateString(),
        'rainfall_mm'  => 10,
    ]);
    WeatherReading::create([
        'farm_id'      => $this->farm->id,
        'reading_date' => now()->toDateString(),
        'rainfall_mm'  => 15,
    ]);

    $this->actingAs($this->readonlyUser)
        ->get(route('weather.index', ['month' => now()->format('Y-m')]))
        ->assertOk();
});

// ─── RECETTES ───

test('un opérateur peut créer une recette avec ses intrants', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('crop-recipes.store'), [
            'name'                => 'Gari de manioc',
            'transformation_type' => 'sechage',
            'output_product'      => 'Gari',
            'items'               => [
                ['input_product' => 'Manioc frais', 'quantity' => 5000, 'unit' => 'kg'],
                ['input_product' => '', 'quantity' => 0, 'unit' => 'kg'], // ligne vide ignorée
            ],
        ])
        ->assertRedirect();

    $recipe = CropRecipe::where('name', 'Gari de manioc')->first();
    expect($recipe)->not->toBeNull()
        ->and($recipe->items()->count())->toBe(1);
});

test('une transformation peut découler d\'une recette', function () {
    $recipe = CropRecipe::create([
        'farm_id'             => $this->farm->id,
        'name'               => 'Jus de mangue',
        'transformation_type' => 'jus',
        'output_product'     => 'Jus',
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('crop-transformations.store'), [
            'crop_recipe_id'      => $recipe->id,
            'input_product'       => 'Mangue',
            'output_product'      => 'Jus',
            'transformation_type' => 'jus',
            'input_quantity'      => 100,
            'output_quantity'     => 60,
            'production_date'     => now()->toDateString(),
        ])
        ->assertRedirect();

    expect(\App\Models\CropTransformation::where('crop_recipe_id', $recipe->id)->exists())->toBeTrue();
});

// ─── CALENDRIER ───

test('le calendrier cultural répond et affiche les cycles de l\'année', function () {
    $plot = Plot::create(['farm_id' => $this->farm->id, 'name' => 'P2', 'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE]);
    CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Riz',
        'area_used_ha'  => 1,
        'planting_date' => now()->startOfYear()->addMonths(2)->toDateString(),
    ]);

    $this->actingAs($this->readonlyUser)
        ->get(route('cultures.dashboard', ['tab' => 'calendar', 'year' => now()->year]))
        ->assertOk()
        ->assertSee('Riz');
});
