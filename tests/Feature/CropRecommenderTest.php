<?php

use App\Models\CropCycle;
use App\Models\CropSpecies;
use App\Models\Plot;
use App\Services\CropAdvisorService;
use Carbon\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function recoMakePlot(int $farmId, array $attrs = []): Plot
{
    return Plot::create(array_merge([
        'farm_id'   => $farmId,
        'name'      => 'Parcelle reco',
        'area_ha'   => 1,
        'status'    => Plot::STATUS_DISPONIBLE,
        'agro_zone' => 'haute_guinee',
        'soil_type' => 'argileux',
    ], $attrs));
}

function recoMakeCycle(int $farmId, Plot $plot, array $attrs = []): CropCycle
{
    return CropCycle::create(array_merge([
        'farm_id'       => $farmId,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Maïs',
        'area_used_ha'  => 1,
        'planting_date' => now()->subDays(30)->toDateString(),
        'status'        => CropCycle::STATUS_EN_COURS,
    ], $attrs));
}

test('recommendCropsForPlot classe en tête une culture qui matche zone + sol + saison', function () {
    $month = (int) now()->month;

    // Match parfait : zone + sol + saison courante.
    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Riz', 'family' => 'Poaceae',
        'agro_zones' => ['haute_guinee'], 'soil_types' => ['argileux'],
        'sowing_months' => [$month], 'avg_yield_tha' => 3.5, 'is_active' => true,
    ]);
    // Culture neutre (autre zone, autre sol, autre saison).
    CropSpecies::create([
        'type' => 'maraicher', 'name' => 'Tomate', 'family' => 'Solanaceae',
        'agro_zones' => ['basse_guinee'], 'soil_types' => ['sableux'],
        'sowing_months' => [($month % 12) + 1], 'avg_yield_tha' => 10, 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);

    $recos = (new CropAdvisorService())->recommendCropsForPlot($plot);

    expect($recos)->not->toBeEmpty();
    expect($recos[0]['species']->name)->toBe('Riz');
    expect($recos[0]['score'])->toBeGreaterThanOrEqual(7);
    expect($recos[0]['reasons'])->not->toBeEmpty();
    expect($recos[0]['in_season'])->toBeTrue();
});

test('recommendCropsForPlot signale avoid pour une culture de même famille que le dernier cycle', function () {
    $month = (int) now()->month;

    CropSpecies::create([
        'type' => 'maraicher', 'name' => 'Tomate', 'family' => 'Solanaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120, 'is_active' => true,
    ]);
    // Même famille (Solanaceae) que le dernier cycle Tomate, mais sinon bien adaptée.
    CropSpecies::create([
        'type' => 'tubercule', 'name' => 'Pomme de terre', 'family' => 'Solanaceae',
        'agro_zones' => ['haute_guinee'], 'soil_types' => ['argileux'],
        'sowing_months' => [$month], 'avg_yield_tha' => 20, 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);
    recoMakeCycle($this->farm->id, $plot, ['crop_name' => 'Tomate']);

    $recos = (new CropAdvisorService())->recommendCropsForPlot($plot->fresh());

    $potato = collect($recos)->firstWhere(fn ($r) => $r['species']->name === 'Pomme de terre');
    expect($potato)->not->toBeNull();
    expect($potato['avoid'])->toBeTrue();
    expect(collect($potato['reasons'])->contains(fn ($r) => str_contains($r, 'Même famille')))->toBeTrue();
});

test('recommendCropsForPlot recommande une légumineuse avec la raison azote après une céréale', function () {
    $month = (int) now()->month;

    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs', 'family' => 'Poaceae', 'is_active' => true,
    ]);
    CropSpecies::create([
        'type' => 'legumineuse', 'name' => 'Niébé', 'family' => 'Fabaceae',
        'agro_zones' => ['haute_guinee'], 'soil_types' => ['argileux'],
        'sowing_months' => [$month], 'avg_yield_tha' => 1.5, 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);
    recoMakeCycle($this->farm->id, $plot, ['crop_name' => 'Maïs']);

    $recos = (new CropAdvisorService())->recommendCropsForPlot($plot->fresh());

    $niebe = collect($recos)->firstWhere(fn ($r) => $r['species']->name === 'Niébé');
    expect($niebe)->not->toBeNull();
    expect(collect($niebe['reasons'])->contains(fn ($r) => str_contains($r, "restaure l'azote")))->toBeTrue();
});

test('monitoringPlan renvoie sowing_ok=false avec une note hors période', function () {
    $month = (int) now()->month;
    $other = ($month % 12) + 1; // un mois différent du mois courant

    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs', 'family' => 'Poaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
        'sowing_months' => [$other], 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);
    $cycle = recoMakeCycle($this->farm->id, $plot, [
        'crop_name'     => 'Maïs',
        'planting_date' => now()->toDateString(), // semé ce mois-ci → hors fenêtre
    ]);

    $plan = (new CropAdvisorService())->monitoringPlan($cycle);

    expect($plan['has_reference'])->toBeTrue();
    expect($plan['sowing_ok'])->toBeFalse();
    expect(collect($plan['notes'])->contains(fn ($n) => str_contains($n, 'hors période conseillée')))->toBeTrue();
});

test('monitoringPlan calcule recommended_harvest_date = semis + cycle_days', function () {
    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs', 'family' => 'Poaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 110, 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);
    $planting = Carbon::create(now()->year, 3, 1);
    $cycle = recoMakeCycle($this->farm->id, $plot, [
        'crop_name'     => 'Maïs',
        'planting_date' => $planting->toDateString(),
    ]);

    $plan = (new CropAdvisorService())->monitoringPlan($cycle);

    expect($plan['cycle_days'])->toBe(110); // cycle_days_max
    expect($plan['recommended_harvest_date']->toDateString())
        ->toBe($planting->copy()->addDays(110)->toDateString());
});

test('monitoringPlan signale un besoin en eau élevé sans irrigation', function () {
    CropSpecies::create([
        'type' => 'maraicher', 'name' => 'Tomate', 'family' => 'Solanaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
        'water_need' => 'eleve', 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id, ['irrigation_type' => null]);
    $cycle = recoMakeCycle($this->farm->id, $plot, ['crop_name' => 'Tomate']);

    $plan = (new CropAdvisorService())->monitoringPlan($cycle);

    expect($plan['water_need'])->toBe('Élevé');
    expect(collect($plan['notes'])->contains(fn ($n) => str_contains($n, 'Besoin en eau élevé')))->toBeTrue();
});

test('la fiche parcelle affiche les Cultures recommandées', function () {
    $month = (int) now()->month;
    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Riz', 'family' => 'Poaceae',
        'agro_zones' => ['haute_guinee'], 'soil_types' => ['argileux'],
        'sowing_months' => [$month], 'avg_yield_tha' => 3.5, 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);

    $this->actingAs($this->managerUser)
        ->get(route('plots.show', $plot))
        ->assertOk()
        ->assertSee('Cultures recommandées')
        ->assertSee('Riz');
});

test('la fiche cycle affiche le Plan de suivi pour une culture du catalogue', function () {
    CropSpecies::create([
        'type' => 'cereale', 'name' => 'Maïs', 'family' => 'Poaceae',
        'cycle_days_min' => 90, 'cycle_days_max' => 120,
        'sowing_months' => [3, 4, 5], 'water_need' => 'moyen',
        'yield_tips' => 'Fertiliser au stade 6 feuilles.', 'is_active' => true,
    ]);

    $plot = recoMakePlot($this->farm->id);
    $cycle = recoMakeCycle($this->farm->id, $plot, ['crop_name' => 'Maïs']);

    $this->actingAs($this->managerUser)
        ->get(route('crop-cycles.show', $cycle))
        ->assertOk()
        ->assertSee('Plan de suivi');
});
