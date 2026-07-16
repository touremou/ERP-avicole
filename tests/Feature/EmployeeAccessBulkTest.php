<?php

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Liaison EN MASSE compte ↔ employé : pour chaque agent sans accès, on lie un
 * compte existant (même email) ou on en crée un (identifiant généré si l'agent
 * n'a pas d'email). Réservé à admin.S.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->fieldRole = Role::firstOrCreate(['name' => 'gardien'], ['label' => 'Gardien', 'display_name' => 'Gardien', 'permissions' => ['L', 'C']]);
});

test('crée un accès pour un agent sans email et sans compte', function () {
    $emp = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'first_name' => 'Mamadou', 'last_name' => 'Toure',
        'email' => null, 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('employees.access.bulk'), ['employee_ids' => [$emp->id], 'role_id' => $this->fieldRole->id])
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionHas('bulk_access_results');

    $emp->refresh();
    expect($emp->user_id)->not->toBeNull()
        ->and($emp->user->role_id)->toBe($this->fieldRole->id)
        ->and($emp->user->email)->toContain('mamadou.toure.'); // identifiant généré
});

test('lie un compte EXISTANT (même email) au lieu d’en créer un', function () {
    $existing = User::factory()->create(['email' => 'awa@ferme.com']);
    $emp = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'email' => 'awa@ferme.com', 'status' => 'Actif',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('employees.access.bulk'), ['employee_ids' => [$emp->id], 'role_id' => $this->fieldRole->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($emp->fresh()->user_id)->toBe($existing->id);
});

test('ignore un agent qui a déjà un accès', function () {
    $user = User::factory()->create();
    $emp = Employee::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $user->id, 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('employees.access.bulk'), ['employee_ids' => [$emp->id], 'role_id' => $this->fieldRole->id])
        ->assertRedirect();

    // Inchangé : toujours lié au même user.
    expect($emp->fresh()->user_id)->toBe($user->id);
});

test('un non-admin ne peut pas lier en masse', function () {
    $emp = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    $nonAdmin = User::factory()->create(['role_id' => $this->fieldRole->id]); // rôle sans admin.S

    $this->actingAs($nonAdmin)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('employees.access.bulk'), ['employee_ids' => [$emp->id], 'role_id' => $this->fieldRole->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($emp->fresh()->user_id)->toBeNull();
});
