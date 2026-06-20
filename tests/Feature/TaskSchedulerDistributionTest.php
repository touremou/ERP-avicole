<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\TaskSchedulerService;
use Carbon\Carbon;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    // Isole le scénario : on neutralise tout template préexistant (référentiel
    // semé) pour ne mesurer QUE la répartition du template sous test.
    TaskTemplate::withoutGlobalScopes()->update(['is_active' => false]);
});

test('la génération répartit les tâches entre les employés disponibles au lieu de tout donner au responsable des lots', function () {
    $date = Carbon::parse('2026-06-22');

    // 3 employés disponibles, aucun gardien dédié de bâtiment.
    $emp1 = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    $emp2 = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    $emp3 = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    // 3 bâtiments, chacun avec un lot actif DONT LE RESPONSABLE EST emp1
    // (cas réel : un seul agent a créé/encadre toutes les bandes).
    $buildingIds = [];
    foreach (range(1, 3) as $i) {
        $building = Building::factory()->create([
            'farm_id' => $this->farm->id, 'type' => 'chair', 'status' => 'Disponible',
        ]);
        $buildingIds[] = $building->id;
        Batch::factory()->create([
            'farm_id'          => $this->farm->id,
            'building_id'      => $building->id,
            'employee_id'      => $emp1->id,
            'status'           => 'Actif',
            'initial_quantity' => 1000,
        ]);
    }

    TaskTemplate::create([
        'name' => 'Alimentation matin', 'category' => 'alimentation',
        'frequency' => 'quotidien', 'duration_minutes' => 30, 'priority' => 'haute',
        'per_building' => true, 'target_type' => 'building', 'is_active' => true,
    ]);

    app(TaskSchedulerService::class)->generateForDate($date, $this->farm->id);

    $byEmployee = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
        ->whereIn('building_id', $buildingIds)
        ->get()
        ->groupBy('employee_id')
        ->map->count();

    // 3 tâches (1 par bâtiment) parfaitement réparties : 1 par employé.
    expect($byEmployee->keys()->filter()->count())->toBe(3);
    expect($byEmployee->max())->toBe(1);
});

test('un gardien dédié reçoit toujours les tâches de son bâtiment', function () {
    $date = Carbon::parse('2026-06-22');

    $building = Building::factory()->create([
        'farm_id' => $this->farm->id, 'type' => 'chair', 'status' => 'Disponible',
    ]);
    Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $building->id,
        'status' => 'Actif', 'initial_quantity' => 1000,
    ]);

    $keeper = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'assigned_building_id' => $building->id,
    ]);
    // Bruit : d'autres employés moins chargés ne doivent PAS voler la tâche.
    Employee::factory()->count(2)->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    TaskTemplate::create([
        'name' => 'Contrôle bâtiment', 'category' => 'controle',
        'frequency' => 'quotidien', 'duration_minutes' => 20, 'priority' => 'normale',
        'per_building' => true, 'target_type' => 'building', 'is_active' => true,
    ]);

    app(TaskSchedulerService::class)->generateForDate($date, $this->farm->id);

    $task = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
        ->where('building_id', $building->id)->first();
    expect($task->employee_id)->toBe($keeper->id);
});

test('un employé en congé n\'est jamais auto-assigné à la génération', function () {
    $date = Carbon::parse('2026-06-22');

    $building = Building::factory()->create([
        'farm_id' => $this->farm->id, 'type' => 'chair', 'status' => 'Disponible',
    ]);
    $onLeave = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $building->id,
        'employee_id' => $onLeave->id, 'status' => 'Actif', 'initial_quantity' => 1000,
    ]);
    $available = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    // Le responsable du lot est en congé sur la date de génération.
    \App\Models\EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $onLeave->id,
        'type' => 'conge_annuel', 'start_date' => $date->copy()->subDay()->toDateString(),
        'end_date' => $date->copy()->addDays(3)->toDateString(), 'days_count' => 5,
        'status' => 'approuve',
    ]);

    TaskTemplate::create([
        'name' => 'Soins', 'category' => 'sante',
        'frequency' => 'quotidien', 'duration_minutes' => 20, 'priority' => 'normale',
        'per_building' => true, 'target_type' => 'building', 'is_active' => true,
    ]);

    app(TaskSchedulerService::class)->generateForDate($date, $this->farm->id);

    $task = TaskAssignment::whereDate('scheduled_date', $date->toDateString())
        ->where('building_id', $building->id)->first();
    expect($task->employee_id)->toBe($available->id);
});
