<?php

use App\Models\Batch;
use App\Models\Farm;
use App\Models\ProductionNorm;
use App\Models\ProductionType;
use App\Models\Species;
use App\Services\BatchAdvisorService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);
});

/** Crée une espèce d'une famille donnée + un barème + un lot, et renvoie le lot. */
function batchOfFamily(int $farmId, string $family, string $type): Batch
{
    $species = Species::firstOrCreate(
        ['slug' => $family . '-test'],
        ['name_fr' => ucfirst($family), 'family' => $family, 'is_active' => true]
    );

    $model = 'TST-' . strtoupper($type);
    ProductionNorm::create([
        'model_name' => $model, 'batch_type' => $type, 'week_number' => 1, 'phase_name' => 'Phase 1',
        'target_feed_daily' => 50, 'target_water_daily' => 100, 'target_weight' => 100, 'target_laying_rate' => 0,
    ]);

    return Batch::factory()->create([
        'farm_id'            => $farmId,
        'species_id'         => $species->id,
        'model_name'         => $model,
        'production_type_id' => ProductionType::resolveOrCreate($type, $species->id)->id,
        'status'             => 'Actif',
        'current_quantity'   => 100,
        'arrival_date'       => now()->subDays(7),
    ]);
}

function thiThresholdOf(Batch $batch): float|int
{
    return app(BatchAdvisorService::class)->recommendation($batch)['environment']['thi_threshold'];
}

test('THI : un poisson (aquaculture) n\'a aucun seuil de stress thermique', function () {
    // « grossissement » est ambigu (poisson OU ruminant) : la FAMILLE tranche.
    $batch = batchOfFamily($this->farm->id, 'aquaculture', 'grossissement');

    expect(thiThresholdOf($batch))->toBe(PHP_INT_MAX);
});

test('THI : un ruminant porte le seuil ruminant', function () {
    $batch = batchOfFamily($this->farm->id, 'grand_ruminant', 'grossissement');

    expect(thiThresholdOf($batch))->toBe(27.0);
});

test('THI : une pondeuse est plus sensible qu\'un poulet de chair', function () {
    $ponte = batchOfFamily($this->farm->id, 'volaille', 'ponte');
    $chair = batchOfFamily($this->farm->id, 'volaille', 'chair');

    expect(thiThresholdOf($ponte))->toBe(24.0)
        ->and(thiThresholdOf($chair))->toBe(25.0);
});

test('THI : un lapin porte le seuil lagomorphe', function () {
    $batch = batchOfFamily($this->farm->id, 'lagomorphe', 'lapin');

    expect(thiThresholdOf($batch))->toBe(25.5);
});
