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
    $permL = Permission::firstOrCreate(['name' => 'L'], ['description' => 'Lecture']);
    $permC = Permission::firstOrCreate(['name' => 'C'], ['description' => 'Création']);
    $permM = Permission::firstOrCreate(['name' => 'M'], ['description' => 'Modification']);
    $permS = Permission::firstOrCreate(['name' => 'S'], ['description' => 'Suppression']);

    $admin = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrateur', 'icon' => '👑']);
    $admin->permissions()->syncWithoutDetaching([$permL->id, $permC->id, $permM->id, $permS->id]);

    $readonly = Role::firstOrCreate(['name' => 'visiteur'], ['display_name' => 'Visiteur', 'icon' => '👁️']);
    $readonly->permissions()->syncWithoutDetaching([$permL->id]);

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
        ->assertSee('Effectif Actif')
        ->assertSee('Ponte (HDP)');
});

test('un utilisateur non connecté est redirigé vers login', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
