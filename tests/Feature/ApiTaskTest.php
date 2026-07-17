<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\Role;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Tâches assignées côté terrain : liste « Mes tâches » (scopée à l'employé
 * rattaché) et complétion hors-ligne via sync (task.complete), autorisée pour
 * SA propre tâche, refusée pour celle d'un autre (hors superviseur annuaire.M).
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    $role = Role::firstOrCreate(['name' => 'gardien'], ['label' => 'Gardien', 'display_name' => 'Gardien', 'permissions' => ['L', 'C']]);
    $this->user = User::factory()->create(['role_id' => $role->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->employee = Employee::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->user->id]);
    session(['current_farm_id' => $this->farm->id]);
});

function makeTask(int $farmId, ?int $employeeId, string $status = 'a_faire'): TaskAssignment
{
    return TaskAssignment::create([
        'farm_id'        => $farmId,
        'employee_id'    => $employeeId,
        'title'          => 'Nettoyer la salle de ponte',
        'category'       => 'nettoyage',
        'scheduled_date' => now()->toDateString(),
        'status'         => $status,
    ]);
}

function completeOp(int $taskId): array
{
    return [
        'op_uuid' => Str::uuid()->toString(),
        'type'    => 'task.complete',
        'payload' => ['uuid' => Str::uuid()->toString(), 'task_id' => $taskId],
    ];
}

test('GET /tasks renvoie les tâches actionnables de mon employé', function () {
    $task = makeTask($this->farm->id, $this->employee->id);

    Sanctum::actingAs($this->user);

    $ids = collect($this->getJson('/api/v1/tasks')->assertOk()->json('tasks'))->pluck('id');
    expect($ids)->toContain($task->id);
});

test('GET /tasks renvoie un récap « ma journée » (aujourd\'hui, retard, prioritaires, faites)', function () {
    // Aujourd'hui (dont une prioritaire), une en retard, une à venir.
    makeTask($this->farm->id, $this->employee->id); // aujourd'hui, nettoyage
    $today = now()->toDateString();
    TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Contrôle urgent',
        'category' => 'controle', 'scheduled_date' => $today, 'priority' => 'critique', 'status' => 'a_faire',
    ]);
    TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Retard',
        'category' => 'nettoyage', 'scheduled_date' => now()->subDay()->toDateString(), 'status' => 'a_faire',
    ]);
    TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Demain',
        'category' => 'nettoyage', 'scheduled_date' => now()->addDay()->toDateString(), 'status' => 'a_faire',
    ]);
    // Une tâche déjà faite aujourd'hui (hors liste, comptée dans le récap).
    TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->employee->id, 'title' => 'Déjà faite',
        'category' => 'nettoyage', 'scheduled_date' => $today, 'status' => 'fait', 'completed_at' => now(),
    ]);

    Sanctum::actingAs($this->user);
    $summary = $this->getJson('/api/v1/tasks')->assertOk()->json('summary');

    expect($summary['today'])->toBe(2)
        ->and($summary['overdue'])->toBe(1)
        ->and($summary['upcoming'])->toBe(1)
        ->and($summary['high_priority'])->toBe(1)
        ->and($summary['done_today'])->toBe(1);
});

test('task.complete termine MA tâche puis renvoie already_synced au rejeu', function () {
    $task = makeTask($this->farm->id, $this->employee->id);
    Sanctum::actingAs($this->user);

    $op = completeOp($task->id);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('success');
    expect($task->fresh()->status)->toBe('fait')
        ->and($task->fresh()->completed_by)->toBe($this->user->id);

    $res2 = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res2['status'])->toBe('already_synced');
});

test("task.complete refuse la tâche d'un autre employé (non superviseur)", function () {
    $other = Employee::factory()->create(['farm_id' => $this->farm->id]);
    $task = makeTask($this->farm->id, $other->id);
    Sanctum::actingAs($this->user);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [completeOp($task->id)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('permission_denied');
    expect($task->fresh()->status)->toBe('a_faire');
});

test('task.create crée une tâche PERSONNELLE (auto-assignée), idempotente', function () {
    Sanctum::actingAs($this->user);

    $uuid = Str::uuid()->toString();
    $op = [
        'op_uuid' => Str::uuid()->toString(),
        'type'    => 'task.create',
        'payload' => [
            'uuid'           => $uuid,
            'title'          => 'Vérifier abreuvoirs B2',
            'category'       => 'controle',
            'scheduled_date' => now()->toDateString(),
            'priority'       => 'haute',
        ],
    ];

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('success');

    $task = TaskAssignment::where('uuid', $uuid)->first();
    expect($task)->not->toBeNull()
        ->and($task->employee_id)->toBe($this->employee->id) // auto-assignée à MOI
        ->and($task->status)->toBe('a_faire');

    // Rejeu réseau → already_synced (pas de doublon).
    $res2 = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res2['status'])->toBe('already_synced');
    expect(TaskAssignment::where('uuid', $uuid)->count())->toBe(1);
});

test('task.create est refusée à un utilisateur sans employé rattaché', function () {
    $orphan = User::factory()->create(['role_id' => $this->user->role_id]); // pas d'Employee lié
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $orphan->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($orphan);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => Str::uuid()->toString(),
        'type'    => 'task.create',
        'payload' => ['uuid' => Str::uuid()->toString(), 'title' => 'X', 'category' => 'controle', 'scheduled_date' => now()->toDateString()],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('permission_denied');
});
