<?php

use App\Models\CropCycle;
use App\Models\CropSpecies;
use App\Models\Plot;
use App\Models\WeatherReading;
use App\Services\CropAdvisorService;
use Carbon\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function advisorMakePlot(int $farmId, array $attrs = []): Plot
{
    return Plot::create(array_merge([
        'farm_id' => $farmId,
        'name'    => 'Parcelle test',
        'area_ha' => 1,
        'status'  => Plot::STATUS_EN_CULTURE,
    ], $attrs));
}

function advisorMakeCycle(int $farmId, Plot $plot, array $attrs = []): CropCycle
{
    return CropCycle::create(array_merge([
        'farm_id'       => $farmId,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Maïs',
        'area_used_ha'  => 1,
        'planting_date' => now()->subDays(60)->toDateString(),
        'status'        => CropCycle::STATUS_EN_COURS,
    ], $attrs));
}

test('un cycle en retard produit un conseil critique de récolte en retard', function () {
    $plot = advisorMakePlot($this->farm->id);
    $cycle = advisorMakeCycle($this->farm->id, $plot, [
        'expected_harvest_date' => now()->subDays(5)->toDateString(),
    ]);

    $advisories = (new CropAdvisorService())->cycleRisks($cycle);

    $overdue = collect($advisories)->firstWhere('title', 'Récolte en retard');
    expect($overdue)->not->toBeNull();
    expect($overdue['severity'])->toBe('critique');
});

test('une céréale récoltée en saison des pluies produit un conseil attention', function () {
    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs', 'family' => 'Poaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
    ]);

    // Semis calé pour que récolte projetée (planting + 120 j) tombe en juillet
    // (grande saison des pluies, mai–oct.).
    $harvestInRainy = Carbon::create(now()->year, 7, 15);
    $plantingDate = $harvestInRainy->copy()->subDays(120);

    $plot = advisorMakePlot($this->farm->id);
    $cycle = advisorMakeCycle($this->farm->id, $plot, [
        'planting_date'         => $plantingDate->toDateString(),
        'expected_harvest_date' => null,
    ]);

    $advisories = (new CropAdvisorService())->cycleRisks($cycle);

    $rainy = collect($advisories)->firstWhere('title', 'Récolte en saison des pluies');
    expect($rainy)->not->toBeNull();
    expect($rainy['severity'])->toBe('attention');
});

test('weatherAlerts renvoie le conseil "Pas de données météo" sans relevé', function () {
    $plot = advisorMakePlot($this->farm->id);

    $advisories = (new CropAdvisorService())->weatherAlerts($plot);

    expect($advisories)->toHaveCount(1);
    expect($advisories[0]['title'])->toBe('Pas de données météo');
    expect($advisories[0]['severity'])->toBe('info');
});

test('weatherAlerts signale les fortes pluies récentes (>= 50 mm)', function () {
    $plot = advisorMakePlot($this->farm->id);
    WeatherReading::create([
        'farm_id'      => $this->farm->id,
        'plot_id'      => $plot->id,
        'reading_date' => now()->subDays(2)->toDateString(),
        'rainfall_mm'  => 65,
    ]);

    $advisories = (new CropAdvisorService())->weatherAlerts($plot);

    $heavy = collect($advisories)->firstWhere('title', 'Fortes pluies récentes');
    expect($heavy)->not->toBeNull();
    expect($heavy['severity'])->toBe('attention');
});

test('rotationSuggestions suggère une légumineuse après une céréale', function () {
    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs', 'family' => 'Poaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
    ]);

    $plot = advisorMakePlot($this->farm->id);
    advisorMakeCycle($this->farm->id, $plot, [
        'crop_name'     => 'Maïs',
        'planting_date' => now()->subDays(30)->toDateString(),
    ]);

    $rotation = (new CropAdvisorService())->rotationSuggestions($plot->fresh());

    $advice = collect($rotation)->firstWhere('title', 'Enrichir le sol');
    expect($advice)->not->toBeNull();
    expect($advice['severity'])->toBe('conseil');
});

test('rotationSuggestions alerte sur deux cultures consécutives de même famille', function () {
    CropSpecies::create([
        'type' => 'maraicher', 'name' => 'Tomate', 'family' => 'Solanaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
    ]);
    CropSpecies::create([
        'type' => 'tubercule', 'name' => 'Pomme de terre', 'family' => 'Solanaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
    ]);

    $plot = advisorMakePlot($this->farm->id);
    advisorMakeCycle($this->farm->id, $plot, [
        'crop_name'     => 'Pomme de terre',
        'planting_date' => now()->subDays(200)->toDateString(),
        'status'        => CropCycle::STATUS_TERMINE,
    ]);
    advisorMakeCycle($this->farm->id, $plot, [
        'crop_name'     => 'Tomate',
        'planting_date' => now()->subDays(30)->toDateString(),
    ]);

    $rotation = (new CropAdvisorService())->rotationSuggestions($plot->fresh());

    $warning = collect($rotation)->firstWhere('title', 'Rotation à respecter');
    expect($warning)->not->toBeNull();
    expect($warning['severity'])->toBe('attention');
});

test('la fiche cycle affiche la section Conseils agronomiques pour un cycle en retard', function () {
    $plot = advisorMakePlot($this->farm->id);
    $cycle = advisorMakeCycle($this->farm->id, $plot, [
        'expected_harvest_date' => now()->subDays(5)->toDateString(),
    ]);

    $this->actingAs($this->managerUser)
        ->get(route('crop-cycles.show', $cycle))
        ->assertOk()
        ->assertSee('Conseils agronomiques')
        ->assertSee('Récolte en retard');
});
