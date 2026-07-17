<?php

use App\Models\Formula;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Provenderie : un « formulateur » (L+C+M) gère les formules mais ne peut
 * NI supprimer une formule (S) NI importer le référentiel normé (opération S).
 * Corrige un décalage de vue : le bouton d'import était affiché au niveau M
 * alors que la route/le contrôleur exigent S.
 */

function provenderieRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'provenderie')->value('id');
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

    $this->formulateur = User::factory()->create(['role_id' => provenderieRole('formulateur', ['L', 'C', 'M'])->id]);
    $this->chef = User::factory()->create(['role_id' => provenderieRole('chef_provenderie', ['L', 'S'])->id]);

    $this->formula = Formula::factory()->create(['farm_id' => $this->farm->id]);
});

test('le formulateur (M) ne peut PAS importer le référentiel normé (route = S)', function () {
    $response = $this->actingAs($this->formulateur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('norms.import'), []);

    expect($response->status())->toBeIn([302, 403]);
});

test('le formulateur (M) ne peut PAS supprimer une formule (S)', function () {
    $this->actingAs($this->formulateur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('formulas.destroy', $this->formula->id))
        ->assertRedirect();

    expect(Formula::whereKey($this->formula->id)->exists())->toBeTrue();
});

test("l'UI des formules cache l'import référentiel (S) au formulateur mais montre Créer (C)", function () {
    $this->actingAs($this->formulateur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('formulas.index'))
        ->assertOk()
        ->assertSee(route('formulas.create'), false)        // C : autorisé
        ->assertDontSee('Mettre à jour le Référentiel');    // S : masqué
});

test('un chef provenderie (S) voit le bouton import référentiel', function () {
    $this->actingAs($this->chef)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('formulas.index'))
        ->assertOk()
        ->assertSee('Mettre à jour le Référentiel');
});
