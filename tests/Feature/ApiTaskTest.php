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
