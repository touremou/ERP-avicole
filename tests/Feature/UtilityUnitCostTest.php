<?php

use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Services\UtilityService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->waterSource = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage',
    ]);
    $this->energySource = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe', 'type' => 'groupe', 'fuel_type' => 'gasoil',
    ]);
});

test('le coût/m³ d\'eau réalisé = coût total ÷ volume consommé', function () {
    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $this->waterSource->id,
        'reading_date' => now()->subDays(2)->toDateString(),
        'volume_consumed_liters' => 2000, 'cost' => 10000, 'user_id' => $this->adminUser->id,
    ]);

    $water = app(UtilityService::class)->getDashboardData(30)['water'];

    // 10 000 GNF ÷ (2000 L / 1000) = 5 000 GNF / m³
    expect($water['cost_per_m3'])->toBe(5000.0);
});

test('le coût/kWh réalisé = coût total ÷ kWh produits', function () {
    EnergyReading::create([
        'farm_id' => $this->farm->id, 'energy_source_id' => $this->energySource->id,
        'reading_date' => now()->subDays(2)->toDateString(),
        'hours_run' => 10, 'kwh_produced' => 100, 'cost' => 50000, 'user_id' => $this->adminUser->id,
    ]);

    $energy = app(UtilityService::class)->getDashboardData(30)['energy'];

    // 50 000 GNF ÷ 100 kWh = 500 GNF / kWh ; 50 000 ÷ 10 h = 5 000 GNF / h
    expect($energy['cost_per_kwh'])->toBe(500.0)
        ->and($energy['cost_per_hour'])->toBe(5000.0);
});

test('sans consommation, les coûts unitaires valent 0 (pas de division par zéro)', function () {
    $data = app(UtilityService::class)->getDashboardData(30);

    expect($data['water']['cost_per_m3'])->toBe(0)
        ->and($data['energy']['cost_per_kwh'])->toBe(0)
        ->and($data['energy']['cost_per_hour'])->toBe(0);
});
