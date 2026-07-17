<?php

use App\Models\Module;
use App\Models\Plot;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Cultures (Production Végétale) : un « agronome » en L+C peut consulter
 * et créer, mais NI modifier (M) NI supprimer (S) une parcelle. Le module est
 * déjà cloisonné sur les trois couches — ce test verrouille le comportement
 * (protection contre régression).
 */

function culturesRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'cultures')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
         'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    $this->agronome = User::factory()->create(['role_id' => culturesRole('agronome', ['L', 'C'])->id]);

    $this->plot = Plot::create([
        'uuid'    => (string) Str::uuid(),
        'farm_id' => $this->farm->id,
        'code'    => 'PARC-RBAC-01',
        'name'    => 'Parcelle maraîchère RBAC',
        'area_ha' => 1.5,
        'status'  => 'active',
    ]);
});

test("l'agronome (C) ne peut PAS modifier une parcelle (route update = M)", function () {
    $response = $this->actingAs($this->agronome)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->put(route('plots.update', $this->plot), ['name' => 'Renommée illégalement', 'area_ha' => 9]);

    expect($response->status())->toBeIn([302, 403]);
    expect($this->plot->fresh()->name)->toBe('Parcelle maraîchère RBAC'); // inchangée
});

test("l'agronome (C) ne peut PAS supprimer une parcelle (route destroy = S)", function () {
    $this->actingAs($this->agronome)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('plots.destroy', $this->plot))
        ->assertRedirect();

    expect(Plot::whereKey($this->plot->id)->exists())->toBeTrue();
});

test("l'UI d'une parcelle ne présente NI Modifier NI Supprimer à l'agronome (couche vue)", function () {
    $this->actingAs($this->agronome)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('plots.show', $this->plot))
        ->assertOk()
        ->assertDontSee(route('plots.edit', $this->plot), false)
        ->assertDontSee(route('plots.destroy', $this->plot), false);
});

test('un manager (M) voit le bouton Modifier de la parcelle', function () {
    $this->actingAs($this->managerUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('plots.show', $this->plot))
        ->assertOk()
        ->assertSee(route('plots.edit', $this->plot), false);
});
