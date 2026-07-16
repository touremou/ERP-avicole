<?php

use App\Models\Employee;
use App\Models\TaskAssignment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Garde-fou métier du planning : une tâche d'élevage (alimentation, collecte,
 * nettoyage, santé, contrôle) ne peut PAS être affectée à un employé d'un
 * autre service (ex. un vendeur du service Logistique). Cohérence du planning.
 */

beforeEach(function () {
    $this->setUpRbac();
});

function farmTask(int $farmId, string $category = 'alimentation'): TaskAssignment
{
    return TaskAssignment::create([
        'farm_id'        => $farmId,
        'title'          => 'Distribuer l’aliment',
        'category'       => $category,
        'scheduled_date' => now()->toDateString(),
        'status'         => 'a_faire',
    ]);
}

test("une tâche d'élevage ne peut PAS être assignée à un vendeur (Logistique)", function () {
    $seller = Employee::factory()->create(['farm_id' => $this->farm->id, 'department' => 'Logistique', 'status' => 'Actif']);
    $task = farmTask($this->farm->id);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.assign', $task), ['employee_id' => $seller->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test("une tâche d'élevage peut être assignée à un employé du service Élevage", function () {
    $farmer = Employee::factory()->create(['farm_id' => $this->farm->id, 'department' => 'Elevage', 'status' => 'Actif']);
    $task = farmTask($this->farm->id);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.assign', $task), ['employee_id' => $farmer->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($task->fresh()->employee_id)->toBe($farmer->id);
});

test('création manuelle : refuse un employé du mauvais service', function () {
    $seller = Employee::factory()->create(['farm_id' => $this->farm->id, 'department' => 'Logistique', 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('tasks.store'), [
            'title'          => 'Nettoyer la salle',
            'category'       => 'nettoyage',
            'employee_id'    => $seller->id,
            'scheduled_date' => now()->toDateString(),
            'priority'       => 'normale',
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(TaskAssignment::where('title', 'Nettoyer la salle')->exists())->toBeFalse();
});
