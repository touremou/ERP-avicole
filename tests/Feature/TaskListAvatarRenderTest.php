<?php

use App\Models\Employee;
use App\Models\TaskAssignment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * La liste des tâches (web) affiche l'avatar de l'employé assigné : photo RH
 * si renseignée (servie via /media), sinon l'avatar par défaut selon le genre.
 */

beforeEach(function () {
    $this->setUpRbac();
});

test("la ligne de tâche affiche la photo RH de l'employé assigné", function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'department' => 'Elevage', 'status' => 'Actif',
        'photo_path' => 'employees/photo-test.jpg',
    ]);
    TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'title' => 'Distribuer l’aliment', 'category' => 'alimentation',
        'scheduled_date' => now()->toDateString(), 'status' => 'a_faire',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('media/employees/photo-test.jpg', false);
});

test("sans photo, la ligne de tâche retombe sur l'avatar par défaut", function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'department' => 'Elevage', 'status' => 'Actif',
        'photo_path' => null, 'gender' => 'F',
    ]);
    TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'title' => 'Nettoyer', 'category' => 'nettoyage',
        'scheduled_date' => now()->toDateString(), 'status' => 'a_faire',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('female-tech.svg', false);
});
