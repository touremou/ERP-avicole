<?php

use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC RH / Annuaire : un gestionnaire du personnel (annuaire.L+C+M) gère les
 * fiches mais ne peut NI archiver un employé (annuaire.S) NI créer un compte de
 * connexion (opération sensible réservée à admin.S). Verrouille aussi les routes
 * ressource employees/providers (jusqu'ici sans middleware de route).
 */

function annuaireRole(string $name, array $modulePerms): Role
{
    // $modulePerms = ['annuaire' => ['L','C','M'], 'admin' => ['S'], ...]
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    foreach ($modulePerms as $slug => $perms) {
        $mod = Module::where('slug', $slug)->value('id');
        if (! $mod) continue;
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $mod],
            ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
             'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    return $role;
}

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    // Gestionnaire RH : annuaire L+C+M, PAS de annuaire.S ni admin.S.
    $this->rh = User::factory()->create(['role_id' => annuaireRole('gestionnaire_rh', ['annuaire' => ['L', 'C', 'M']])->id]);
    // Lecteur annuaire seul.
    $this->lecteur = User::factory()->create(['role_id' => annuaireRole('lecteur_rh', ['annuaire' => ['L']])->id]);

    $this->employee = Employee::factory()->create(['farm_id' => $this->farm->id]);
});

test("le gestionnaire RH (M) ne peut PAS archiver un employé (annuaire.S)", function () {
    $this->actingAs($this->rh)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('employees.destroy', $this->employee))
        ->assertRedirect();

    // Non archivé : toujours présent et actif.
    expect(Employee::whereKey($this->employee->id)->exists())->toBeTrue();
});

test("le gestionnaire RH ne peut PAS créer un compte de connexion (admin.S)", function () {
    $response = $this->actingAs($this->rh)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('employees.access.store', $this->employee), ['email' => 'x@example.com', 'role_id' => $this->rh->role_id]);

    // Verrou de route can:admin.S : refus.
    expect($response->status())->toBeIn([302, 403]);
    expect($this->employee->fresh()->user_id)->toBeNull();
});

test("le lecteur RH (L) ne peut PAS embaucher (route store = C)", function () {
    $response = $this->actingAs($this->lecteur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('employees.store'), ['first_name' => 'Test', 'last_name' => 'Intrus']);

    expect($response->status())->toBeIn([302, 403]);
});

test("l'UI fiche employé cache la gestion de compte (admin.S) au gestionnaire RH", function () {
    // Le gestionnaire RH (sans admin.S) voit la fiche mais pas les contrôles de compte.
    $this->actingAs($this->rh)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('employees.show', $this->employee))
        ->assertOk()
        ->assertDontSee(route('employees.access.store', $this->employee), false)
        ->assertDontSee(route('employees.access.password', $this->employee), false);

    // L'admin (bypass, dont admin.S) voit bien le bouton Modifier.
    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('employees.show', $this->employee))
        ->assertOk()
        ->assertSee(route('employees.edit', $this->employee), false);
});
