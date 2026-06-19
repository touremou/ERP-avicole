<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->building = Building::factory()->create(['name' => 'Bâtiment A', 'farm_id' => $this->farm->id]);

    $this->waterSource = WaterSource::create([
        'farm_id' => $this->farm->id,
        'name'    => 'Forage principal',
        'type'    => 'forage',
    ]);

    $this->energySource = EnergySource::create([
        'farm_id' => $this->farm->id,
        'name'    => 'Groupe A',
        'type'    => 'groupe',
        'fuel_type' => 'gasoil',
    ]);
});

test('un relevé eau taggé sur un bâtiment est imputable au lot actif dans ce bâtiment', function () {
    $batch = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'status'       => 'actif',
    ]);

    WaterReading::create([
        'farm_id'                => $this->farm->id,
        'water_source_id'        => $this->waterSource->id,
        'building_id'            => $this->building->id,
        'reading_date'           => now()->subDays(5)->toDateString(),
        'volume_consumed_liters' => 1000,
        'cost'                   => 5000,
        'user_id'                => $this->adminUser->id,
    ]);

    expect($batch->utility_cost)->toBe(5000.0);
});

test('un relevé énergie taggé est inclus dans utility_cost', function () {
    $batch = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'status'       => 'actif',
    ]);

    EnergyReading::create([
        'farm_id'         => $this->farm->id,
        'energy_source_id'=> $this->energySource->id,
        'building_id'     => $this->building->id,
        'reading_date'    => now()->subDays(3)->toDateString(),
        'hours_run'       => 8,
        'cost'            => 12000,
        'user_id'         => $this->adminUser->id,
    ]);

    expect($batch->utility_cost)->toBe(12000.0);
});

test('utility_cost cumule eau + énergie pour la période du lot', function () {
    $batch = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'status'       => 'actif',
    ]);

    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $this->waterSource->id,
        'building_id' => $this->building->id, 'reading_date' => now()->subDays(5)->toDateString(),
        'volume_consumed_liters' => 1000, 'cost' => 5000, 'user_id' => $this->adminUser->id,
    ]);

    EnergyReading::create([
        'farm_id' => $this->farm->id, 'energy_source_id' => $this->energySource->id,
        'building_id' => $this->building->id, 'reading_date' => now()->subDays(3)->toDateString(),
        'hours_run' => 8, 'cost' => 12000, 'user_id' => $this->adminUser->id,
    ]);

    expect($batch->utility_cost)->toBe(17000.0);
});

test('un relevé sans building_id n\'est pas imputé au lot', function () {
    $batch = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'status'       => 'actif',
    ]);

    // Relevé global (non taggé à un bâtiment)
    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $this->waterSource->id,
        'building_id' => null, 'reading_date' => now()->subDays(5)->toDateString(),
        'volume_consumed_liters' => 5000, 'cost' => 25000, 'user_id' => $this->adminUser->id,
    ]);

    expect($batch->utility_cost)->toBe(0.0);
});

test('un relevé antérieur à l\'arrivée du lot n\'est pas imputé', function () {
    $batch = Batch::factory()->create([
        'farm_id'      => $this->farm->id,
        'building_id'  => $this->building->id,
        'arrival_date' => now()->subDays(5)->toDateString(),
        'status'       => 'actif',
    ]);

    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $this->waterSource->id,
        'building_id' => $this->building->id,
        'reading_date' => now()->subDays(10)->toDateString(), // avant l'arrivée
        'volume_consumed_liters' => 1000, 'cost' => 9999, 'user_id' => $this->adminUser->id,
    ]);

    expect($batch->utility_cost)->toBe(0.0);
});

test('le formulaire dashboard eau accepte un building_id', function () {
    $this->actingAs($this->adminUser)
        ->post(route('utilities.water.readings.store'), [
            'water_source_id'        => $this->waterSource->id,
            'building_id'            => $this->building->id,
            'reading_date'           => now()->toDateString(),
            'volume_consumed_liters' => 500,
        ])
        ->assertSessionHas('success');

    expect(WaterReading::where('building_id', $this->building->id)->exists())->toBeTrue();
});

test('le formulaire dashboard énergie accepte un building_id', function () {
    $this->actingAs($this->adminUser)
        ->post(route('utilities.energy.readings.store'), [
            'energy_source_id' => $this->energySource->id,
            'building_id'      => $this->building->id,
            'reading_date'     => now()->toDateString(),
            'hours_run'        => 6,
        ])
        ->assertSessionHas('success');

    expect(EnergyReading::where('building_id', $this->building->id)->exists())->toBeTrue();
});
