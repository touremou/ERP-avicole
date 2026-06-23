<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Services\BatchAdvisorService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    // Barème Ross 308 minimal (semaines 3 et 5) pour tester l'interpolation.
    foreach ([
        [3, 'Croissance', 960, 95, 175],
        [5, 'Finition', 2200, 175, 320],
    ] as [$week, $phase, $w, $feed, $water]) {
        ProductionNorm::create([
            'batch_type'         => 'chair',
            'week_number'        => $week,
            'phase_name'         => $phase,
            'model_name'         => 'Ross 308',
            'target_weight'      => $w,
            'target_feed_daily'  => $feed,
            'target_water_daily' => $water,
            'target_laying_rate' => 0,
        ]);
    }
});

function advisorMakeBatch(array $attrs = []): Batch
{
    return Batch::factory()->create(array_merge([
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
        'model_name'         => 'Ross 308',
        'current_quantity'   => 1000,
        'initial_quantity'   => 1000,
        'arrival_date'       => now()->subDays(24), // ~ semaine 4
    ], $attrs));
}

test('interpole le barème à la semaine et met à l\'échelle sur l\'effectif', function () {
    $batch = advisorMakeBatch();
    // Pointage en ambiance neutre (pas de stress thermique).
    DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => now()->toDateString(),
        'temp_max'   => 28,
        'humidity'   => 50,
        'avg_weight' => 1.55, // kg
    ]);

    $reco = (new BatchAdvisorService())->recommendation($batch->fresh());

    expect($reco)->not->toBeNull();
    expect($reco['week'])->toBe(4);
    // Semaine 4 = milieu de [3,5] : aliment ≈ (95+175)/2 = 135 g.
    expect($reco['per_subject']['feed_g'])->toEqualWithDelta(135.0, 0.5);
    // Eau ≈ (175+320)/2 = 247,5 ml (au-dessus du plancher 135×1,8).
    expect($reco['per_subject']['water_ml'])->toEqualWithDelta(247.5, 0.5);
    // Total = 135 g × 1000 / 1000 = 135 kg.
    expect($reco['total']['feed_kg'])->toEqualWithDelta(135.0, 0.5);
    // THI(28°C, 50%HR) = 28 − 0.275×13.6 = 24.26 < seuil chair (25.0) → pas de stress.
    expect($reco['environment']['heat_stress'])->toBeFalse();
});

test('le stress thermique majore l\'eau, réduit l\'aliment et émet une alerte', function () {
    $batch = advisorMakeBatch();
    DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => now()->toDateString(),
        'temp_max'   => 38, // +8 °C au-dessus du seuil
        'humidity'   => 50,
        'avg_weight' => 1.55,
    ]);

    $advisor = new BatchAdvisorService();
    $reco = $advisor->recommendation($batch->fresh());

    expect($reco['environment']['heat_stress'])->toBeTrue();
    // THI(38°C, 50%HR) = 38 − 0.275×23.6 ≈ 31.51 ; seuil chair = 25 ; over ≈ 6.51
    // Facteur eau = 1 + min(0,6 ; 6,51×0,05) ≈ 1,326 ; aliment = max(0,85 ; 1 − 6,51×0,015) ≈ 0,902
    expect($reco['environment']['water_factor'])->toEqualWithDelta(1.326, 0.002);
    expect($reco['environment']['feed_factor'])->toEqualWithDelta(0.902, 0.002);
    // Aliment réduit sous le barème neutre (135 g).
    expect($reco['per_subject']['feed_g'])->toBeLessThan(135.0);

    $advisories = $advisor->advisories($batch->fresh());
    expect(collect($advisories)->pluck('title'))->toContain('Stress thermique');
});

test('signale une sous-distribution d\'aliment', function () {
    $batch = advisorMakeBatch();
    DailyCheck::factory()->create([
        'batch_id'      => $batch->id,
        'check_date'    => now()->toDateString(),
        'temp_max'      => 28,
        'humidity'      => 50,
        'avg_weight'    => 1.55,
        'feed_consumed' => 50, // kg, très en dessous des ~135 kg attendus
    ]);

    $advisories = (new BatchAdvisorService())->advisories($batch->fresh());

    expect(collect($advisories)->pluck('title'))->toContain('Sous-distribution d\'aliment');
});

test('retourne null sans barème applicable', function () {
    $batch = advisorMakeBatch(['model_name' => 'Souche Inconnue XYZ', 'production_type_id' => ProductionType::resolveOrCreate('grossissement', null)->id]);

    $reco = (new BatchAdvisorService())->recommendation($batch->fresh());

    expect($reco)->toBeNull();
});
