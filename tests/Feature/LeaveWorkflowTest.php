<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\TaskAssignment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    /*
     * HORLOGE FIGÉE SUR UN LUNDI — sinon ce fichier dépend du jour où la CI tourne.
     *
     * Les congés d'ici sont ancrés sur `now()` (« actif aujourd'hui »), et leur
     * coût en jours se compte désormais en jours OUVRÉS : une fenêtre de trois
     * jours civils vaut 3 jours ouvrés si elle part un lundi, et 2 si elle part
     * un vendredi — elle enjambe alors le repos hebdomadaire.
     *
     * Ce fichier passait donc certains jours et échouait les autres, sans qu'une
     * ligne de code ait changé entre-temps. On fige l'horloge sur un lundi : les
     * ancrages `now()` redeviennent déterministes, et ce que le test mesure ne
     * dépend plus du calendrier.
     */
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-08 08:00:00'));   // un lundi

    $this->setUpRbac();

    /*
     * DATE D'EMBAUCHE EXPLICITE, et non tirée au sort.
     *
     * La factory tire une embauche au hasard sur deux ans, et la visibilité d'un
     * agent tient à une AFFECTATION couvrant la date du jour
     * (`Employee::scopeVisibleInFarm`). Avec l'horloge figée, une embauche tirée
     * après cette date rendait l'agent invisible : `storeLeave` refusait « cet
     * employé n'est pas rattaché à cette ferme », et le fichier échouait une fois
     * sur deux — sur un décor, pas sur le code mesuré.
     */
    $this->employee = Employee::factory()->create([
        'status'               => 'Actif',
        'annual_leave_balance' => 30,
        'hire_date'            => '2024-01-15',
        'contract_end_date'    => null,
    ]);
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
    $colleague = Employee::factory()->create(['status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null]);

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

test('un employé peut déléguer ses propres tâches en libre-service', function () {
    // Créer un utilisateur lié à l'employé absent (self-service)
    $absentUser = \App\Models\User::factory()->create(['role_id' => $this->readonlyUser->role_id]);
    $this->employee->update(['user_id' => $absentUser->id]);

    $colleague = Employee::factory()->create(['status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null]);

    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'conge_annuel', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(), 'days_count' => 6,
        'status' => 'approuve',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Suivi cheptel',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'status' => 'a_faire', 'priority' => 'normale', 'is_auto_generated' => false,
    ]);

    // L'employé absent (rôle lecture seule, pas annuaire.M) peut déléguer son propre congé
    $this->actingAs($absentUser)
        ->post(route('payroll.leaves.delegate', $leave), ['delegate_to' => $colleague->id])
        ->assertSessionHas('success');

    expect($task->fresh()->employee_id)->toBe($colleague->id);
});

test('un employé sans lien sur le congé ne peut pas déléguer les tâches d\'un autre', function () {
    $otherEmployee = Employee::factory()->create(['status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null]);
    $otherUser = \App\Models\User::factory()->create(['role_id' => $this->readonlyUser->role_id]);
    $otherEmployee->update(['user_id' => $otherUser->id]);

    $colleague = Employee::factory()->create(['status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null]);

    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'conge_annuel', 'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(5)->toDateString(), 'days_count' => 6,
        'status' => 'approuve',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Suivi',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'status' => 'a_faire', 'priority' => 'normale', 'is_auto_generated' => false,
    ]);

    // Un tiers sans droit annuaire.M NI lien sur ce congé → refus
    $this->actingAs($otherUser)
        ->post(route('payroll.leaves.delegate', $leave), ['delegate_to' => $colleague->id])
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBe($this->employee->id);
});

test('une demande en attente déclenche une notification WhatsApp aux responsables RH', function () {
    $hub = $this->mock(\App\Services\NotificationHub::class);
    $hub->shouldReceive('notifyLeaveRequested')->once();

    $this->actingAs($this->managerUser) // pas de droit S → demande en attente
        ->post(route('payroll.leaves.store'), [
            'employee_id' => $this->employee->id,
            'type'        => 'conge_annuel',
            'start_date'  => now()->addDays(10)->toDateString(),
            'end_date'    => now()->addDays(15)->toDateString(),
        ])
        ->assertSessionHas('success');
});

test('l\'approbation d\'un congé notifie l\'employé concerné', function () {
    $hub = $this->mock(\App\Services\NotificationHub::class);
    $hub->shouldReceive('notifyLeaveApproved')->once();

    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'maladie', 'start_date' => now()->addDays(5)->toDateString(),
        'end_date' => now()->addDays(10)->toDateString(), 'days_count' => 6,
        'status' => 'demande', 'requested_by' => $this->managerUser->id,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.approve', $leave))
        ->assertSessionHas('success');
});

test('le refus d\'un congé notifie l\'employé avec le motif', function () {
    $hub = $this->mock(\App\Services\NotificationHub::class);
    $hub->shouldReceive('notifyLeaveRejected')->once();

    $leave = EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id,
        'type' => 'maladie', 'start_date' => now()->addDays(5)->toDateString(),
        'end_date' => now()->addDays(10)->toDateString(), 'days_count' => 6,
        'status' => 'demande', 'requested_by' => $this->managerUser->id,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.reject', $leave), ['rejection_reason' => 'Pointe de saison, effectif requis'])
        ->assertSessionHas('success');

    expect($leave->fresh()->rejection_reason)->toBe('Pointe de saison, effectif requis');
});
