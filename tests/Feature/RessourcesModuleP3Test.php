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
    $this->service = app(UtilityService::class);
});

/** Crée n relevés eau quotidiens stables puis un pic le dernier jour. */
function seedWaterReadings(int $sourceId, int $farmId, int $userId, array $volumes): void
{
    foreach ($volumes as $i => $vol) {
        WaterReading::create([
            'farm_id'                => $farmId,
            'water_source_id'        => $sourceId,
            'reading_date'           => now()->subDays(count($volumes) - 1 - $i)->toDateString(),
            'user_id'                => $userId,
            'volume_consumed_liters' => $vol,
        ]);
    }
}

test('une consommation d\'eau dans la norme ne déclenche aucune anomalie', function () {
    $source = WaterSource::create(['farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage', 'is_active' => true]);

    // 6 relevés stables autour de 1000 L
    seedWaterReadings($source->id, $this->farm->id, $this->operatorUser->id, [1000, 1050, 980, 1020, 1010, 990]);

    $anomalies = $this->service->detectAnomalies();
    expect(collect($anomalies)->where('type', 'anomaly_water'))->toBeEmpty();
});

test('un pic de consommation d\'eau déclenche une alerte de fuite', function () {
    $source = WaterSource::create(['farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage', 'is_active' => true]);

    // Base ~1000 L puis pic à 2000 L (+100%)
    seedWaterReadings($source->id, $this->farm->id, $this->operatorUser->id, [1000, 1000, 1000, 1000, 1000, 2000]);

    $anomalies = collect($this->service->detectAnomalies())->where('type', 'anomaly_water');
    expect($anomalies)->toHaveCount(1);
    expect($anomalies->first()['severity'])->toBe('attention');
    expect($anomalies->first()['title'])->toContain('Forage');
});

test('une source d\'eau récente (base insuffisante) ne déclenche pas d\'anomalie', function () {
    $source = WaterSource::create(['farm_id' => $this->farm->id, 'name' => 'Neuf', 'type' => 'forage', 'is_active' => true]);

    // Seulement 3 relevés, dont un pic — base trop courte (< 5)
    seedWaterReadings($source->id, $this->farm->id, $this->operatorUser->id, [500, 500, 5000]);

    expect(collect($this->service->detectAnomalies())->where('type', 'anomaly_water'))->toBeEmpty();
});

test('une surconsommation horaire d\'un groupe déclenche une alerte moteur', function () {
    $source = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe Perkins', 'type' => 'groupe',
        'fuel_type' => 'gasoil', 'is_active' => true,
    ]);

    // Base à 10 L/h (100L/10h) puis dérive à 20 L/h (200L/10h) → +100%
    $rates = [[100, 10], [100, 10], [100, 10], [100, 10], [100, 10], [200, 10]];
    foreach ($rates as $i => [$fuel, $hours]) {
        EnergyReading::create([
            'farm_id'              => $this->farm->id,
            'energy_source_id'     => $source->id,
            'reading_date'         => now()->subDays(count($rates) - 1 - $i)->toDateString(),
            'user_id'              => $this->operatorUser->id,
            'hours_run'            => $hours,
            'fuel_consumed_liters' => $fuel,
        ]);
    }

    $anomalies = collect($this->service->detectAnomalies())->where('type', 'anomaly_energy');
    expect($anomalies)->toHaveCount(1);
    expect($anomalies->first()['title'])->toContain('Groupe Perkins');
});

test('les anomalies remontent dans getAlerts() et donc sur le dashboard', function () {
    $source = WaterSource::create(['farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage', 'is_active' => true]);
    seedWaterReadings($source->id, $this->farm->id, $this->operatorUser->id, [1000, 1000, 1000, 1000, 1000, 2200]);

    $alerts = $this->service->getAlerts();
    expect(collect($alerts)->where('type', 'anomaly_water'))->not->toBeEmpty();
});

test('le seuil d\'anomalie est paramétrable', function () {
    $source = WaterSource::create(['farm_id' => $this->farm->id, 'name' => 'Forage', 'type' => 'forage', 'is_active' => true]);

    // +40% : sous le seuil par défaut (50%) → rien
    seedWaterReadings($source->id, $this->farm->id, $this->operatorUser->id, [1000, 1000, 1000, 1000, 1000, 1400]);
    expect(collect($this->service->detectAnomalies())->where('type', 'anomaly_water'))->toBeEmpty();

    // Abaisse le seuil à 30% → l'écart de +40% devient une anomalie
    \App\Models\Setting::updateOrCreate(
        ['key' => 'anomaly_threshold_pct'],
        ['group' => 'energie', 'value' => '30', 'type' => 'number']
    );
    cache()->flush();

    expect(collect($this->service->detectAnomalies())->where('type', 'anomaly_water'))->not->toBeEmpty();
});
