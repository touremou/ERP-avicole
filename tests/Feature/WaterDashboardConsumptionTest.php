<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Farm;
use App\Models\User;
use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Services\UtilityService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $this->building = Building::factory()->create([
        'farm_id' => $this->farm->id, 'type' => 'chair', 'capacity' => 5000,
    ]);
    $this->batch = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'status' => 'Actif', 'current_quantity' => 1000,
    ]);
});

test('le dashboard eau agrège la conso des pointages ET des relevés manuels', function () {
    // 300 L saisis au pointage (pas de double saisie côté module Eau).
    DailyCheck::factory()->create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $this->batch->id,
        'check_date'     => now()->toDateString(),
        'water_consumed' => 300,
        'mortality'      => 0,
    ]);

    // 100 L relevés manuellement sur une citerne.
    $src = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Citerne A', 'type' => 'citerne',
        'capacity_liters' => 10000, 'current_level_liters' => 10000, 'current_level_percent' => 100, 'is_active' => true,
    ]);
    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $src->id, 'user_id' => User::factory()->create()->id,
        'reading_date' => now()->toDateString(), 'volume_consumed_liters' => 100,
    ]);

    $water = app(UtilityService::class)->getDashboardData(30)['water'];

    expect((float) $water['total_consumed'])->toBe(400.0)
        ->and((float) $water['from_daily_checks'])->toBe(300.0)
        ->and((float) $water['from_readings'])->toBe(100.0);
});

test('sans relevé manuel, la conso eau provient entièrement des pointages', function () {
    DailyCheck::factory()->create([
        'farm_id'        => $this->farm->id,
        'batch_id'       => $this->batch->id,
        'check_date'     => now()->toDateString(),
        'water_consumed' => 250,
        'mortality'      => 0,
    ]);

    $water = app(UtilityService::class)->getDashboardData(30)['water'];

    expect((float) $water['total_consumed'])->toBe(250.0)
        ->and((float) $water['from_readings'])->toBe(0.0);
});
