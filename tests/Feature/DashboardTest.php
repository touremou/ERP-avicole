<?php

/**
 * Tests Feature — Dashboard
 *
 * Couvre : DS-01 (pas de crash), mode offline, accès permissions
 */

use App\Models\Building;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Setup RBAC directement
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $makeRole = fn (string $name, array $perms) => Role::firstOrCreate(
        ['name' => $name],
        ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
    );

    $admin    = $makeRole('admin',  ['L', 'C', 'M', 'S']);
    $readonly = $makeRole('viewer', ['L']);

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
    $this->readonlyUser = User::factory()->create(['role_id' => $readonly->id]);
});

test('le dashboard charge sans crash (DS-01 corrigé)', function () {
    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Undefined variable');
});

test('le dashboard est accessible à un visiteur (L)', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('dashboard'))
        ->assertOk();
});

test('le dashboard affiche les KPI de base', function () {
    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        // KPI de base, toujours rendu (le KPI Ponte/HDP est conditionnel à
        // l'existence d'un lot de ponte).
        ->assertSee('Effectif Actif');
});

test('un utilisateur non connecté est redirigé vers login', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
