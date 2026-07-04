<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Services\BatchAdvisorService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Chantier A (pré-MEP 2026-07-04) — référentiel de souche enrichi d'après le
 * guide d'élevage officiel ISA Brown (Hendrix Genetics) :
 * - normes S1-18 avec fourchettes conso/poids, programme lumineux (h + lux),
 *   températures bâtiment et uniformité cible (≥ 80 %) ;
 * - uniformité mesurée saisie au pointage (part des sujets à ±10 % du poids
 *   moyen) ;
 * - advisories dérivés : rappel lumière, température hors plage, lot
 *   hétérogène — silencieux pour les souches sans fiche enrichie.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->seed(Database\Seeders\ProductionNormSeeder::class);

    // Bande ISA Brown en semaine 6 (âge 38 j) — norme guide : 41-43 g/j,
    // 482-507 g, 12 h de lumière à 10 lux, 17-20 °C, uniformité ≥ 80 %.
    $this->pondeuse = Batch::factory()->create([
        'production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id,
        'model_name'         => 'ISA Brown',
        'arrival_date'       => now()->subDays(38),
        'initial_quantity'   => 500,
        'current_quantity'   => 500,
        'qty_alive'          => 500,
    ]);
});

test('le seeder charge le guide ISA Brown : fourchettes, lumière, lux, T°, uniformité', function () {
    $s6 = ProductionNorm::where('model_name', 'ISA Brown')->where('week_number', 6)->first();

    expect($s6)->not->toBeNull();
    expect((float) $s6->feed_min_daily)->toEqual(41.0);
    expect((float) $s6->feed_max_daily)->toEqual(43.0);
    expect((float) $s6->weight_min)->toEqual(482.0);
    expect((float) $s6->weight_max)->toEqual(507.0);
    expect((float) $s6->light_hours)->toEqual(12.0);
    expect((int) $s6->light_lux_min)->toBe(10);
    expect((float) $s6->temp_min_c)->toEqual(17.0);
    expect((float) $s6->temp_max_c)->toEqual(20.0);
    expect((float) $s6->uniformity_target)->toEqual(80.0);

    // Couverture hebdomadaire complète de l'élevage poulette (S1-18).
    expect(ProductionNorm::where('model_name', 'ISA Brown')
        ->whereBetween('week_number', [1, 18])->count())->toBe(18);
});

test('l\'uniformité mesurée se saisit au pointage quotidien et est persistée', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'       => $this->pondeuse->id,
            'check_date'     => now()->toDateString(),
            'mortality'      => 0,
            'feed_consumed'  => 0,
            'feed_type'      => 'Ponte Croissance',
            'water_consumed' => 42,
            'health_status'  => 'Normal',
            'avg_weight'     => 0.495,
            'uniformity_pct' => 76.5,
        ])
        ->assertSessionHasNoErrors();

    $check = DailyCheck::where('batch_id', $this->pondeuse->id)->first();
    expect((float) $check->uniformity_pct)->toEqual(76.5);
});

test('advisories guide : rappel lumière + température hors plage + lot hétérogène', function () {
    DailyCheck::factory()->create([
        'batch_id'       => $this->pondeuse->id,
        'check_date'     => now()->toDateString(),
        'mortality'      => 0,
        'temp_min'       => 22,
        'temp_max'       => 27,      // guide S6 : 17-20 °C → hors plage
        'avg_weight'     => 0.495,
        'uniformity_pct' => 65,      // < 80 - 10 → critique
    ]);

    $advisories = collect(app(BatchAdvisorService::class)->advisories($this->pondeuse->fresh()->load('dailyChecks')));
    $titles = $advisories->pluck('title');

    expect($titles)->toContain('Programme lumineux (guide souche)');
    expect($titles)->toContain('Température hors plage du guide');
    expect($titles)->toContain('Uniformité du lot insuffisante');

    $light = $advisories->firstWhere('title', 'Programme lumineux (guide souche)');
    expect($light['message'])->toContain('12 h');

    $unif = $advisories->firstWhere('title', 'Uniformité du lot insuffisante');
    expect($unif['severity'])->toBe('critique'); // 65 % très en dessous des 80 %
});

test('souche sans fiche enrichie : aucun advisory guide (pas de bruit)', function () {
    $chair = Batch::factory()->create([
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
        'model_name'         => 'Ross 308',
        'arrival_date'       => now()->subDays(14),
        'initial_quantity'   => 500,
        'current_quantity'   => 500,
        'qty_alive'          => 500,
    ]);

    DailyCheck::factory()->create([
        'batch_id'       => $chair->id,
        'check_date'     => now()->toDateString(),
        'mortality'      => 0,
        'uniformity_pct' => 60, // pas de cible chez Ross 308 → silencieux
    ]);

    $titles = collect(app(BatchAdvisorService::class)->advisories($chair->fresh()->load('dailyChecks')))
        ->pluck('title');

    expect($titles)->not->toContain('Programme lumineux (guide souche)');
    expect($titles)->not->toContain('Uniformité du lot insuffisante');
});
