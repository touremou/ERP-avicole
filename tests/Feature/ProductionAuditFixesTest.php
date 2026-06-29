<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\EggProduction;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\ProductionType;
use App\Services\EggAnalysisService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('le rapport œufs ne plante plus et calcule le HDP sur les vraies colonnes', function () {
    $building = Building::factory()->create(['type' => 'ponte']);
    $batch = Batch::factory()->create([
        'building_id'        => $building->id,
        'status'             => 'Actif',
        'production_type_id' => ProductionType::resolveOrCreate('ponte', null)->id,
        'initial_quantity'   => 200,
        'current_quantity'   => 200,
    ]);
    EggProduction::create([
        'farm_id'              => session('current_farm_id'),
        'batch_id'             => $batch->id,
        'production_date'      => now()->subDay()->toDateString(),
        'total_eggs_collected' => 160,
        'broken_eggs'          => 4,
        'small_eggs'           => 10,
        'laying_rate'          => 80,
    ]);

    // Avant correctif : « Unknown column collection_date » → exception.
    $report = (new EggAnalysisService())->getDailyReport();

    expect($report['has_layers'])->toBeTrue();
    $rep = collect($report['batch_reports'])->firstWhere('batch.id', $batch->id);
    // HDP = 160 / 200 × 100 = 80 % (lu sur total_eggs_collected, pas total_collected).
    expect($rep['hdp'])->toBe(80.0)
        ->and($rep['eggs'])->toBe(160.0);
});

test('le KPI « incubations en cours » exclut les cycles clos', function () {
    $building = Building::factory()->create(['type' => 'reproducteur']);
    $batch = Batch::factory()->create(['building_id' => $building->id, 'status' => 'Actif']);
    $incubator = Incubator::create(['name' => 'Couveuse 1', 'capacity' => 500, 'status' => 'Occupé']);

    $mk = fn (string $status) => Incubation::create([
        'batch_id' => $batch->id, 'incubator_id' => $incubator->id,
        'code_incubation' => 'INC-' . fake()->unique()->numerify('#####'),
        'start_date' => now()->subDays(5), 'incubation_duration' => 21,
        'hatch_date_expected' => now()->addDays(16), 'eggs_count' => 100, 'status' => $status,
    ]);
    $mk('incubation');
    $mk('mirage_fait');
    $mk('clos'); // ne doit PAS compter

    $kpis = $this->actingAs($this->adminUser)->get(route('productions.index'))
        ->assertOk()->viewData('kpis');

    expect($kpis['incub_open'])->toBe(2); // 2 en cours, le clos exclu
});
