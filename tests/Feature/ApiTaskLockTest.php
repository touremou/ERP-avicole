<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Verrou de tâche (anti-doublon) : prise (task.start) exclusive, refus au 2ᵉ,
 * complétion bloquée pour un autre que le preneur, libération (task.release)
 * et réarmement automatique des prises expirées (timeout).
 */

function lockRole(string $name, array $levels): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    $mod = Module::where('slug', 'rh')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => true, 'can_create' => in_array('C', $levels), 'can_modify' => in_array('M', $levels),
         'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-LOCK'], ['name' => 'Ferme Lock', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    // Ouvrier (propriétaire de la tâche) et superviseur (rh.M) — deux acteurs
    // pouvant agir sur la même tâche, donc pouvant se marcher dessus.
    $this->worker = User::factory()->create(['role_id' => lockRole('ouvrier_lock', ['L'])->id]);
    $this->supervisor = User::factory()->create(['role_id' => lockRole('chef_lock', ['L', 'C', 'M'])->id]);
    foreach ([$this->worker, $this->supervisor] as $u) {
        DB::table('farm_user')->insert([
            'farm_id' => $this->farm->id, 'user_id' => $u->id,
            'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->workerEmp = Employee::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->worker->id]);
});

function lockTask(int $farmId, int $employeeId): TaskAssignment
{
    return TaskAssignment::create([
        'farm_id' => $farmId, 'employee_id' => $employeeId, 'title' => 'Alimentation B1',
        'category' => 'alimentation', 'scheduled_date' => now()->toDateString(), 'status' => 'a_faire',
    ]);
}

function op(string $type, int $taskId): array
{
    return ['op_uuid' => Str::uuid()->toString(), 'type' => $type,
            'payload' => ['uuid' => Str::uuid()->toString(), 'task_id' => $taskId]];
}

test('task.start prend la tâche (en_cours + claimed_by), idempotent au rejeu du même agent', function () {
    $task = lockTask($this->farm->id, $this->workerEmp->id);
    Sanctum::actingAs($this->worker);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])
        ->assertOk()->json('results.0');
    expect($res['status'])->toBe('success');

    $task->refresh();
    expect($task->status)->toBe('en_cours')
        ->and($task->claimed_by)->toBe($this->worker->id)
        ->and($task->started_at)->not->toBeNull();

    // Rejeu par le même agent → already_synced.
    $again = $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])
        ->assertOk()->json('results.0');
    expect($again['status'])->toBe('already_synced');
});

test('une tâche déjà prise est refusée à un autre acteur (conflict « déjà prise par X »)', function () {
    $task = lockTask($this->farm->id, $this->workerEmp->id);

    // L'ouvrier la prend.
    Sanctum::actingAs($this->worker);
    $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])->assertOk();

    // Le superviseur (rh.M) tente de la prendre → refus.
    Sanctum::actingAs($this->supervisor);
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])
        ->assertOk()->json('results.0');
    expect($res['status'])->toBe('conflict');
});

test('anti-doublon : un autre ne peut pas TERMINER une tâche prise par l\'ouvrier', function () {
    $task = lockTask($this->farm->id, $this->workerEmp->id);
    Sanctum::actingAs($this->worker);
    $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])->assertOk();

    // Le superviseur tente de clôturer à sa place → conflict.
    Sanctum::actingAs($this->supervisor);
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [op('task.complete', $task->id)]])
        ->assertOk()->json('results.0');
    expect($res['status'])->toBe('conflict');
    expect($task->fresh()->status)->toBe('en_cours'); // toujours en cours, non close

    // Le preneur, lui, peut la terminer.
    Sanctum::actingAs($this->worker);
    $ok = $this->postJson('/api/v1/sync/push', ['operations' => [op('task.complete', $task->id)]])
        ->assertOk()->json('results.0');
    expect($ok['status'])->toBe('success');
    expect($task->fresh()->status)->toBe('fait');
});

test('task.release rend la tâche disponible', function () {
    $task = lockTask($this->farm->id, $this->workerEmp->id);
    Sanctum::actingAs($this->worker);
    $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])->assertOk();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [op('task.release', $task->id)]])
        ->assertOk()->json('results.0');
    expect($res['status'])->toBe('success');

    $task->refresh();
    expect($task->status)->toBe('a_faire')
        ->and($task->claimed_by)->toBeNull()
        ->and($task->started_at)->toBeNull();
});

test('les prises expirées sont réarmées par tasks:release-stale', function () {
    $task = lockTask($this->farm->id, $this->workerEmp->id);
    $task->update([
        'status' => 'en_cours', 'claimed_by' => $this->worker->id,
        'started_at' => now()->subMinutes(TaskAssignment::CLAIM_TIMEOUT_MINUTES + 10),
    ]);

    $this->artisan('tasks:release-stale')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe('a_faire')->and($task->claimed_by)->toBeNull();
});

test('GET /tasks expose le verrou (claimed_by_me pour le preneur)', function () {
    $task = lockTask($this->farm->id, $this->workerEmp->id);
    Sanctum::actingAs($this->worker);
    $this->postJson('/api/v1/sync/push', ['operations' => [op('task.start', $task->id)]])->assertOk();

    $row = collect($this->getJson('/api/v1/tasks')->assertOk()->json('tasks'))->firstWhere('id', $task->id);
    expect($row['status'])->toBe('en_cours')
        ->and($row['claimed_by_me'])->toBeTrue()
        ->and($row['locked'])->toBeFalse(); // ma propre prise ne me verrouille pas
});
