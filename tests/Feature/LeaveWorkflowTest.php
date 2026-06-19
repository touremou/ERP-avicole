<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\TaskAssignment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->employee = Employee::factory()->create(['status' => 'Actif', 'annual_leave_balance' => 30]);
});

test('une saisie par un habilité (droit S) approuve directement le congé', function () {
    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.store'), [
            'employee_id' => $this->employee->id,
            'type'        => 'conge_annuel',
            'start_date'  => now()->toDateString(),
            'end_date'    => now()->addDays(2)->toDateString(),
        ])
        ->assertSessionHas('success');

    $leave = EmployeeLeave::where('employee_id', $this->employee->id)->first();
    expect($leave->status)->toBe('approuve');
    expect($leave->approved_at)->not->toBeNull();
    // Congé actif aujourd'hui → statut employé basculé + solde décompté
    expect($this->employee->fresh()->status)->toBe('Congé');
    expect((int) $this->employee->fresh()->annual_leave_balance)->toBe(27);
});

test('une demande par un non-habilité reste en attente d\'approbation', function () {
    $this->actingAs($this->managerUser) // L,C,M mais PAS S
        ->post(route('payroll.leaves.store'), [
            'employee_id' => $this->employee->id,
            'type'        => 'conge_annuel',
            'start_date'  => now()->toDateString(),
            'end_date'    => now()->addDays(2)->toDateString(),
        ])
        ->assertSessionHas('success');

    $leave = EmployeeLeave::where('employee_id', $this->employee->id)->first();
    expect($leave->status)->toBe('demande');
    expect($leave->approved_by)->toBeNull();
    // Aucun effet tant que non approuvé
    expect($this->employee->fresh()->status)->toBe('Actif');
    expect((int) $this->employee->fresh()->annual_leave_balance)->toBe(30);
});

test('un habilité peut approuver une demande en attente', function () {
    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'conge_annuel', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(2)->toDateString(), 'days_count' => 3,
        'status' => 'demande', 'requested_by' => $this->managerUser->id,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.approve', $leave))
        ->assertSessionHas('success');

    expect($leave->fresh()->status)->toBe('approuve');
    expect($this->employee->fresh()->status)->toBe('Congé');
});

test('un refus consigne le motif et n\'a aucun effet sur l\'employé', function () {
    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'conge_annuel', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(2)->toDateString(), 'days_count' => 3,
        'status' => 'demande', 'requested_by' => $this->managerUser->id,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.reject', $leave), ['rejection_reason' => 'Effectif insuffisant'])
        ->assertSessionHas('success');

    expect($leave->fresh()->status)->toBe('refuse');
    expect($leave->fresh()->rejection_reason)->toBe('Effectif insuffisant');
    expect($this->employee->fresh()->status)->toBe('Actif');
});

test('isOnLeaveOn détecte un congé approuvé couvrant la date', function () {
    EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'absence', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(), 'days_count' => 6,
        'status' => 'approuve',
    ]);

    expect($this->employee->isOnLeaveOn(now()->addDays(2)))->toBeTrue();
    expect($this->employee->isOnLeaveOn(now()->addDays(10)))->toBeFalse();
});

test('on ne peut pas affecter une tâche à un employé en congé', function () {
    EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'absence', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(), 'days_count' => 6,
        'status' => 'approuve',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'title' => 'Nettoyage',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'status' => 'a_faire', 'priority' => 'normale', 'is_auto_generated' => false,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $this->employee->id])
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test('la délégation réaffecte les tâches de l\'absent vers un collègue', function () {
    $colleague = Employee::factory()->create(['status' => 'Actif']);

    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'absence', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(), 'days_count' => 6,
        'status' => 'approuve',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Soins',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'status' => 'a_faire', 'priority' => 'normale', 'is_auto_generated' => false,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.delegate', $leave), ['delegate_to' => $colleague->id])
        ->assertSessionHas('success');

    expect($task->fresh()->employee_id)->toBe($colleague->id);
});
