<?php

use App\Actions\DailyCheck\SyncWaterConsumption;
use App\Actions\DailyCheck\RecordDailyCheck;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Farm;
use App\Models\WaterSource;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);
});

/** Crée une citerne pleine (10 000 L) pour la ferme de test. */
function cistern(int $farmId, array $attrs = []): WaterSource
{
    return WaterSource::create(array_merge([
        'farm_id'               => $farmId,
        'name'                  => 'Citerne A',
        'type'                  => 'citerne',
        'capacity_liters'       => 10000,
        'current_level_liters'  => 10000,
        'current_level_percent' => 100,
        'is_active'             => true,
    ], $attrs));
}

/** Bâtiment + lot actif rattachés à la ferme de test. */
function buildingWithBatch(int $farmId, ?int $waterSourceId): array
{
    $building = Building::factory()->create([
        'farm_id'         => $farmId,
        'type'            => 'chair',
        'capacity'        => 5000,
        'water_source_id' => $waterSourceId,
    ]);

    $batch = Batch::factory()->create([
        'farm_id'          => $farmId,
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
        'arrival_date'     => now()->subDays(5),
    ]);

    return [$building, $batch];
}

test('la conso d\'eau décrémente la citerne affectée au bâtiment', function () {
    $src = cistern($this->farm->id);
    [, $batch] = buildingWithBatch($this->farm->id, $src->id);

    app(SyncWaterConsumption::class)->execute($batch, 0, 300);

    $src->refresh();
    expect((float) $src->current_level_liters)->toBe(9700.0)
        ->and((float) $src->current_level_percent)->toBe(97.0);
});

test('rectifier la conso réajuste la citerne par le DELTA (compensation)', function () {
    // Citerne déjà à 9 700 L après une 1re saisie de 300 L.
    $src = cistern($this->farm->id, ['current_level_liters' => 9700, 'current_level_percent' => 97]);
    [, $batch] = buildingWithBatch($this->farm->id, $src->id);

    // 300 → 500 L : seul le +200 de delta est imputé.
    app(SyncWaterConsumption::class)->execute($batch, 300, 500);

    expect((float) $src->fresh()->current_level_liters)->toBe(9500.0);
});

test('supprimer la conso restitue le niveau de la citerne', function () {
    $src = cistern($this->farm->id, ['current_level_liters' => 9500, 'current_level_percent' => 95]);
    [, $batch] = buildingWithBatch($this->farm->id, $src->id);

    app(SyncWaterConsumption::class)->execute($batch, 500, 0);

    expect((float) $src->fresh()->current_level_liters)->toBe(10000.0);
});

test('sans source affectée, on retombe sur la source PAR DÉFAUT de la ferme', function () {
    $default = cistern($this->farm->id, ['name' => 'Citerne Défaut', 'is_default' => true]);
    [, $batch] = buildingWithBatch($this->farm->id, null); // bâtiment sans source

    app(SyncWaterConsumption::class)->execute($batch, 0, 100);

    expect((float) $default->fresh()->current_level_liters)->toBe(9900.0);
});

test('sans citerne ni défaut : aucun effet, aucune erreur', function () {
    [, $batch] = buildingWithBatch($this->farm->id, null);

    app(SyncWaterConsumption::class)->execute($batch, 0, 100); // ne doit pas lever

    expect(true)->toBeTrue();
});

test('une source réseau (sans cuve) n\'est pas décrémentée', function () {
    $seeg = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'SEEG', 'type' => 'seeg',
        'capacity_liters' => null, 'current_level_liters' => 0, 'is_active' => true, 'is_default' => true,
    ]);
    [, $batch] = buildingWithBatch($this->farm->id, null);

    app(SyncWaterConsumption::class)->execute($batch, 0, 100); // pas de capacité → no-op

    expect((float) $seeg->fresh()->current_level_liters)->toBe(0.0);
});

test('câblage : créer un pointage avec conso d\'eau décrémente la citerne', function () {
    $src = cistern($this->farm->id);
    [, $batch] = buildingWithBatch($this->farm->id, $src->id);

    app(RecordDailyCheck::class)->execute([
        'batch_id'      => $batch->id,
        'check_date'    => now()->toDateString(),
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Chair Démarrage',
        'water_consumed' => 250,
    ]);

    expect((float) $src->fresh()->current_level_liters)->toBe(9750.0);
});
