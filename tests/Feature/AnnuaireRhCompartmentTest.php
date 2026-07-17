<?php

use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Cloisonnement Annuaire (Tiers) vs RH (interne). Le bug corrigé : un rôle
 * « Vendeur » à qui l'on accorde l'Annuaire pour gérer des FOURNISSEURS ne
 * doit PAS accéder aux données du personnel (employés, salaires, congés,
 * paie) — désormais dans le module distinct « rh ».
 */

function compartmentRole(string $name, array $moduleLevels): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    foreach ($moduleLevels as $slug => $perms) {
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

    // Vendeur : Annuaire (Tiers) L+C UNIQUEMENT — pour gérer des fournisseurs.
    $this->vendeur = User::factory()->create([
        'role_id' => compartmentRole('vendeur_tiers', ['annuaire' => ['L', 'C']])->id,
    ]);
    // Gestionnaire RH : rh L+C+M.
    $this->rhManager = User::factory()->create([
        'role_id' => compartmentRole('gestionnaire_rh_c', ['rh' => ['L', 'C', 'M']])->id,
    ]);

    Employee::factory()->create(['farm_id' => $this->farm->id, 'first_name' => 'Salaire', 'last_name' => 'Confidentiel', 'salary' => 9999999]);
});

test("le Vendeur (annuaire) PEUT gérer les fournisseurs", function () {
    $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('providers.index'))->assertOk();
    // Et son hub Annuaire répond (tiers).
    $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('annuaire.index'))->assertOk();
});

test("le Vendeur (annuaire) ne peut PAS accéder aux employés / paie / congés (RH)", function () {
    foreach (['employees.index', 'payroll.index', 'payroll.leaves', 'attendance.index', 'rh.index'] as $route) {
        $response = $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
            ->get(route($route));
        expect($response->status())->toBeIn([302, 403], "Route RH {$route} devrait être refusée au Vendeur");
    }
});

test("le hub Annuaire du Vendeur ne divulgue AUCUNE donnée RH (ni masse salariale)", function () {
    $this->actingAs($this->vendeur)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('annuaire.index'))->assertOk()
        ->assertDontSee('Masse salariale')
        ->assertDontSee('Effectif actif')
        ->assertDontSee('9 999 999');
});

test("le Vendeur ne voit PAS la tuile RH dans le lanceur de modules", function () {
    $slugs = $this->vendeur->getAccessibleModules()->pluck('slug');
    expect($slugs->contains('annuaire'))->toBeTrue()   // Tiers : accordé
        ->and($slugs->contains('rh'))->toBeFalse()      // RH : NON accordé
        ->and($slugs->contains('admin'))->toBeFalse();  // Admin : jamais
});

test("le gestionnaire RH accède bien aux employés (contrôle positif)", function () {
    $this->actingAs($this->rhManager)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('employees.index'))->assertOk();
    $this->actingAs($this->rhManager)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('rh.index'))->assertOk();

    $slugs = $this->rhManager->getAccessibleModules()->pluck('slug');
    expect($slugs->contains('rh'))->toBeTrue()
        ->and($slugs->contains('annuaire'))->toBeFalse(); // n'a PAS les tiers
});
