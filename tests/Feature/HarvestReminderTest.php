<?php

use App\Models\CropCycle;
use App\Models\Plot;
use App\Services\NotificationHub;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function cycleWithHarvestDate(int $farmId, ?string $date, string $status = CropCycle::STATUS_EN_COURS): CropCycle
{
    $plot = Plot::create(['farm_id' => $farmId, 'name' => 'P', 'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE]);

    return CropCycle::create([
        'farm_id'               => $farmId,
        'plot_id'               => $plot->id,
        'crop_name'             => 'Maïs',
        'area_used_ha'          => 1,
        'planting_date'         => now()->subDays(60)->toDateString(),
        'expected_harvest_date' => $date,
        'status'                => $status,
    ]);
}

test('le scope dueForHarvest inclut les récoltes proches et en retard, exclut les lointaines et archivées', function () {
    cycleWithHarvestDate($this->farm->id, now()->addDays(3)->toDateString());            // proche → inclus
    cycleWithHarvestDate($this->farm->id, now()->subDays(2)->toDateString());            // en retard → inclus
    cycleWithHarvestDate($this->farm->id, now()->addDays(30)->toDateString());           // lointain → exclu
    cycleWithHarvestDate($this->farm->id, now()->addDays(2)->toDateString(), CropCycle::STATUS_TERMINE); // archivé → exclu
    cycleWithHarvestDate($this->farm->id, null);                                          // sans date → exclu

    expect(CropCycle::dueForHarvest(7)->count())->toBe(2);
});

test('notifyHarvestsDue renvoie le nombre de cycles à signaler', function () {
    cycleWithHarvestDate($this->farm->id, now()->addDays(1)->toDateString());
    cycleWithHarvestDate($this->farm->id, now()->toDateString());

    // On évite tout envoi réseau : aucun abonné WhatsApp configuré → broadcast no-op.
    $count = app(NotificationHub::class)->notifyHarvestsDue(7);

    expect($count)->toBe(2);
});

test('notifyHarvestsDue renvoie 0 quand aucune récolte n\'est proche', function () {
    cycleWithHarvestDate($this->farm->id, now()->addDays(60)->toDateString());

    expect(app(NotificationHub::class)->notifyHarvestsDue(7))->toBe(0);
});

test('la commande cultures:harvest-reminders s\'exécute sans erreur', function () {
    cycleWithHarvestDate($this->farm->id, now()->addDays(2)->toDateString());

    $this->artisan('cultures:harvest-reminders', ['--days' => 7])
        ->assertSuccessful();
});
