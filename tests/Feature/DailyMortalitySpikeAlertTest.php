<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Farm;
use App\Services\NotificationHub;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);
    $this->building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
});

function batchWith(int $qty): Batch
{
    return Batch::factory()->create([
        'building_id'      => test()->building->id,
        'status'           => 'Actif',
        'initial_quantity' => $qty,
        'current_quantity' => $qty,
    ]);
}

test('un pic de mortalité quotidien déclenche une alerte (par bâtiment)', function () {
    $batch = batchWith(1000);

    $captured = null;
    $this->mock(NotificationHub::class)
        ->shouldReceive('alertDailyMortalitySpike')
        ->once()
        ->andReturnUsing(function ($b, $deaths, $rate) use (&$captured) { $captured = [$b->id, $deaths, $rate]; });

    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'mortality' => 10, 'feed_consumed' => 0, 'feed_type' => 'Démarrage',
    ]); // 10 morts / 1000 = 1 % ≥ 0,5 % et ≥ 3 → alerte

    expect($captured)->toBe([$batch->id, 10, 1.0]);
});

test('un décès isolé sous le minimum absolu ne déclenche pas d\'alerte', function () {
    $batch = batchWith(1000);

    $this->mock(NotificationHub::class)->shouldReceive('alertDailyMortalitySpike')->never();

    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'mortality' => 2, 'feed_consumed' => 0, 'feed_type' => 'Démarrage',
    ]); // 2 < 3 (minimum absolu) → pas d'alerte
});

test('au-dessus du minimum mais sous le taux seuil : pas d\'alerte (gros lot)', function () {
    $batch = batchWith(5000);

    $this->mock(NotificationHub::class)->shouldReceive('alertDailyMortalitySpike')->never();

    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'mortality' => 4, 'feed_consumed' => 0, 'feed_type' => 'Démarrage',
    ]); // 4/5000 = 0,08 % < 0,5 % → pas d'alerte malgré 4 ≥ 3
});

test('le seuil de pic est piloté par les paramètres', function () {
    $batch = batchWith(1000);
    \App\Models\Setting::set('elevage.daily_mortality_alert_min', 20); // on relève le minimum

    $this->mock(NotificationHub::class)->shouldReceive('alertDailyMortalitySpike')->never();

    DailyCheck::factory()->create([
        'batch_id' => $batch->id, 'mortality' => 10, 'feed_consumed' => 0, 'feed_type' => 'Démarrage',
    ]); // 10 < 20 (nouveau minimum) → plus d'alerte
});
