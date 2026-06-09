<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Stock;
use App\Services\DashboardService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new DashboardService();
    $this->building = Building::factory()->create();
});

test('DS-01 : getOnlineData retourne emergencyBatches et underperformingBatches', function () {
    $data = $this->service->getOnlineData();

    expect($data)->toHaveKeys([
        'emergencyBatches', 'underperformingBatches',
        'activeBatches', 'totalBirds', 'globalMortalityRate',
        'hdp', 'safeProfit',
    ]);
    expect($data['emergencyBatches'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($data['underperformingBatches'])->toBeInstanceOf(\Illuminate\Support\Collection::class);
});

test('DS-01 : getOfflineData retourne les mêmes clés avec offline_mode=true', function () {
    $data = $this->service->getOfflineData();

    expect($data)->toHaveKeys(['emergencyBatches', 'underperformingBatches', 'offline_mode']);
    expect($data['offline_mode'])->toBeTrue();
});

test('un lot avec mortalité quotidienne élevée apparaît dans emergencyBatches', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'type'             => 'chair',
        'initial_quantity' => 1000,
        'current_quantity' => 990,
        'arrival_date'     => now()->subDays(30),
    ]);

    DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => now(),
        'mortality'  => 5,
    ]);

    $data = $this->service->getOnlineData();
    expect($data['emergencyBatches']->contains('id', $batch->id))->toBeTrue();
});

test('totalBirds somme les lots actifs uniquement', function () {
    // Compter les lots actifs déjà existants (créés par d'autres tests ou beforeEach)
    $existingBirds = (int) Batch::where('status', 'Actif')->sum('current_quantity');

    $employee = \App\Models\Employee::factory()->create();
    $provider = \App\Models\Provider::factory()->create();

    Batch::factory()->create([
        'building_id' => $this->building->id, 'employee_id' => $employee->id,
        'provider_id' => $provider->id, 'status' => 'Actif', 'current_quantity' => 500,
    ]);
    Batch::factory()->create([
        'building_id' => $this->building->id, 'employee_id' => $employee->id,
        'provider_id' => $provider->id, 'status' => 'Actif', 'current_quantity' => 300,
    ]);
    Batch::factory()->create([
        'building_id' => $this->building->id, 'employee_id' => $employee->id,
        'provider_id' => $provider->id, 'status' => 'Terminé', 'current_quantity' => 200,
    ]);

    $data = $this->service->getOnlineData();
    // On vérifie le delta : les 2 lots actifs ajoutent 800, le lot Terminé n'est pas compté
    expect($data['totalBirds'])->toBe($existingBirds + 800);
});

test('un silo vide apparaît en critique', function () {
    Stock::factory()->create([
        'item_name' => 'Chair Démarrage', 'category' => 'conso',
        'current_quantity' => 0, 'unit' => 'KG',
    ]);

    $data = $this->service->getOnlineData();
    $critical = collect($data['criticalTypes'])->firstWhere('type', 'Chair Démarrage');
    expect($critical)->not->toBeNull();
    expect($critical['days'])->toBe(0);
});
