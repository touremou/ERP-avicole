<?php

use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Permission;
use App\Models\RawMaterial;
use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $makeRole = fn (string $name, array $perms) => Role::firstOrCreate(
        ['name' => $name],
        ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
    );

    $admin    = $makeRole('admin',    ['L', 'C', 'M', 'S']);
    $operator = $makeRole('operator', ['L', 'C']);

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
