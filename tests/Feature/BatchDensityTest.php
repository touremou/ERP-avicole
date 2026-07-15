<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
});

test('la densité courante reflète l\'effectif vivant réel, pas l\'initial', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'initial_quantity' => 1000,
        'current_quantity' => 950,   // 50 morts
        'allocated_surface' => 50,   // m²
    ]);

    // 950 / 50 = 19 sujets/m² (et NON 1000/50 = 20 de la densité planifiée).
    expect($batch->current_density)->toBe(19.0);
});

test('la densité est nulle si la surface allouée est inconnue', function () {
    $batch = Batch::factory()->create([
        'building_id'       => $this->building->id,
        'status'            => 'Actif',
        'current_quantity'  => 500,
        'allocated_surface' => null,
    ]);

    expect($batch->current_density)->toBe(0.0)
        ->and($batch->current_stocking_weight)->toBe(0.0);
});

test('la charge pondérale (kg/m²) combine densité et poids moyen vif', function () {
    $batch = Batch::factory()->create([
        'building_id'       => $this->building->id,
        'status'            => 'Actif',
        'current_quantity'  => 1000,
        'allocated_surface' => 50,
    ]);
    DailyCheck::create([
        'farm_id' => session('current_farm_id'), 'batch_id' => $batch->id,
        'check_date' => now()->toDateString(), 'mortality' => 0,
        'avg_weight' => 2.0, // kg
    ]);

    // 1000 × 2.0 / 50 = 40 kg/m².
    expect($batch->current_stocking_weight)->toBe(40.0);
});
