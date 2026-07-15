<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\WaterReading;
use App\Models\WaterSource;
use App\Services\DashboardInsightsService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
    $this->batch = Batch::factory()->create([
        'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 1000, 'current_quantity' => 1000,
    ]);
});

test('consolidatedTrends agrège mortalité + eau (pointage + relevé) + énergie par jour', function () {
    $today = now()->toDateString();

    DailyCheck::factory()->create([
        'batch_id' => $this->batch->id, 'check_date' => $today,
        'mortality' => 5, 'water_consumed' => 200, 'feed_consumed' => 0, 'feed_type' => 'Démarrage',
    ]);

    $waterSource = WaterSource::create(['farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage']);
    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $waterSource->id, 'reading_date' => $today,
        'volume_consumed_liters' => 300, 'cost' => 0, 'user_id' => $this->adminUser->id,
    ]);

    $energySource = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe', 'type' => 'groupe',
        'depreciation_years' => 5, 'total_hours_run' => 0, 'maintenance_interval_hours' => 250,
        'status' => 'operationnel', 'is_active' => true,
    ]);
    EnergyReading::create([
        'farm_id' => $this->farm->id, 'energy_source_id' => $energySource->id, 'reading_date' => $today,
        'hours_run' => 5, 'cost' => 12000, 'user_id' => $this->adminUser->id,
    ]);

    $series = (new DashboardInsightsService())->consolidatedTrends([$this->batch->id], 30);

    expect($series['labels'])->toHaveCount(30)
        ->and(array_sum($series['mortality']))->toBe(5)
        ->and(array_sum($series['water']))->toBe(500)     // 200 (pointage) + 300 (relevé)
        ->and(array_sum($series['energy']))->toBe(12000);
});

test('la page analytique consolidée s\'affiche avec ses 3 axes', function () {
    $this->adminUser->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($this->adminUser)
        ->get(route('dashboard.analytics'))
        ->assertOk()
        ->assertSee('Vue analytique consolidée')
        ->assertSee('Mortalité / jour')
        ->assertSee('Eau (L) / jour')
        ->assertSee('mortalityChart', false)
        ->assertSee('waterChart', false)
        ->assertSee('energyChart', false);
});

test('la période est bornée entre 7 et 90 jours', function () {
    $this->adminUser->forceFill(['email_verified_at' => now()])->save();

    $resp = $this->actingAs($this->adminUser)->get(route('dashboard.analytics', ['days' => 999]));
    $resp->assertOk();
    expect($resp->viewData('days'))->toBe(90)
        ->and($resp->viewData('series')['labels'])->toHaveCount(90);
});
