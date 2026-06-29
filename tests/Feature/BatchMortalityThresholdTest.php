<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Setting;
use App\Notifications\IndustrialAlert;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
});

test('le seuil de mortalité cumulée vient de la clé libellée, avec repli sur l\'ancienne', function () {
    // Par défaut : 5 %.
    expect(Batch::cumulativeMortalityThreshold())->toBe(5.0);

    // La clé éditable « cumulative_mortality_alert_pct » prime.
    Setting::set('elevage.cumulative_mortality_alert_pct', '8');
    expect(Batch::cumulativeMortalityThreshold())->toBe(8.0);
});

test('le scope critical() suit le seuil unifié édité par l\'admin', function () {
    // Lot à 6 % de mortalité cumulée, via un pointage (source réelle du scope).
    $batch = Batch::factory()->create([
        'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 100, 'current_quantity' => 94,
    ]);
    \App\Models\DailyCheck::create([
        'farm_id' => session('current_farm_id'), 'batch_id' => $batch->id,
        'check_date' => now()->toDateString(), 'mortality' => 6,
    ]);

    // Seuil 5 % → le lot est critique.
    Setting::set('elevage.cumulative_mortality_alert_pct', '5');
    expect(Batch::query()->critical()->whereKey($batch->id)->exists())->toBeTrue();

    // L'admin relève le seuil à 10 % → le même lot n'est plus critique.
    Setting::set('elevage.cumulative_mortality_alert_pct', '10');
    expect(Batch::query()->critical()->whereKey($batch->id)->exists())->toBeFalse();
});

test('l\'alerte de l\'observer se déclenche au seuil cumulé édité', function () {
    Setting::set('elevage.cumulative_mortality_alert_pct', '4');
    Notification::fake();

    $batch = Batch::factory()->create([
        'building_id' => $this->building->id, 'status' => 'Actif',
        'initial_quantity' => 100, 'current_quantity' => 100, 'qty_dead' => 0,
    ]);

    // Franchit le seuil de 4 % (passe à 94 vivants = 6 % cumulé) → alerte admin.
    $batch->update(['current_quantity' => 94]);

    Notification::assertSentTo($this->adminUser, IndustrialAlert::class);
});
