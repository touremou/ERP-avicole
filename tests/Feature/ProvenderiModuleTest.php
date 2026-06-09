<?php

use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Permission;
use App\Models\RawMaterial;
use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $permL = Permission::firstOrCreate(['name' => 'L'], ['description' => 'Lecture']);
    $permC = Permission::firstOrCreate(['name' => 'C'], ['description' => 'Création']);
    $permM = Permission::firstOrCreate(['name' => 'M'], ['description' => 'Modification']);
    $permS = Permission::firstOrCreate(['name' => 'S'], ['description' => 'Suppression']);

    $admin = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrateur', 'icon' => '👑']);
    $admin->permissions()->syncWithoutDetaching([$permL->id, $permC->id, $permM->id, $permS->id]);

    $operator = Role::firstOrCreate(['name' => 'operateur'], ['display_name' => 'Opérateur', 'icon' => '📋']);
    $operator->permissions()->syncWithoutDetaching([$permL->id, $permC->id]);

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
    $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
});

test('un opérateur peut ajouter une matière première', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('raw-materials.store'), [
            'name'            => 'Maïs jaune test',
            'unit'            => 'kg',
            'stock_qty'       => 500,
            'unit_cost'       => 3000,
            'alert_threshold' => 50,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(RawMaterial::where('name', 'Maïs jaune test')->exists())->toBeTrue();
});

test('suppression formule impossible si déjà produite (P-12)', function () {
    $formula = Formula::factory()->create();
    MillProduction::factory()->create(['formula_id' => $formula->id]);

    $this->actingAs($this->adminUser)
        ->delete(route('formulas.destroy', $formula))
        ->assertRedirect()
        ->assertSessionHas('error');
});
