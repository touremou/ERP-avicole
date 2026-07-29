<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Visibilité RH multi-ferme : un employé apparaît dans la liste d'un site s'il y
 * a une AFFECTATION en cours — mutation (son dossier y est) ou mise à
 * disposition (il y travaille sans que son dossier bouge).
 *
 * Cette règle se déduisait auparavant du farm_id du dossier ET de l'accès du
 * compte à la ferme : deux faits sans rapport, dont personne n'avait décidé la
 * combinaison. D'où sa divergence dans une dizaine d'écrans.
 */

function rhViewerRole(): Role
{
    $role = Role::firstOrCreate(['name' => 'rh_viewer'], ['label' => 'RH', 'display_name' => 'RH', 'permissions' => ['L']]);
    $mod = Module::where('slug', 'rh')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => true, 'can_create' => false, 'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

test('un employé rattaché à un autre site mais AYANT ACCÈS à la ferme courante apparaît dans la liste RH', function () {
    $farmA = Farm::create(['code' => 'RHV-A', 'name' => 'Site A', 'is_active' => true]);
    $farmB = Farm::create(['code' => 'RHV-B', 'name' => 'Site B', 'is_active' => true]);

    // Employé « maison » du site A, avec un compte utilisateur.
    $empUser = User::factory()->create(['role_id' => rhViewerRole()->id]);
    $employee = Employee::factory()->create([
        'farm_id' => $farmA->id, 'user_id' => $empUser->id,
        'last_name' => 'Diallo', 'first_name' => 'Amadou', 'status' => 'Actif',
    ]);

    // On lui donne l'ACCÈS au site B (farm_user) — comme la gestion de site…
    DB::table('farm_user')->insert([
        'farm_id' => $farmB->id, 'user_id' => $empUser->id,
        'is_default' => false, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // …et on DÉCLARE sa mise à disposition sur ce site. L'accès du compte seul ne
    // suffit plus : il ouvrait des droits sans que personne ait décidé d'affecter
    // quelqu'un, ce qui est précisément ce que le modèle d'affectation remplace.
    $employee->lendTo($farmB->id, today()->subMonth());

    // Un responsable RH consultant le site B.
    $rh = User::factory()->create(['role_id' => rhViewerRole()->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $farmB->id, 'user_id' => $rh->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($rh)->withSession(['current_farm_id' => $farmB->id])
        ->get(route('employees.index'))
        ->assertOk()
        ->assertSee('Diallo'); // visible bien qu'il soit « rattaché » au site A
});

test('un employé sans accès à la ferme courante n\'y apparaît pas (isolation)', function () {
    $farmA = Farm::create(['code' => 'RHV-C', 'name' => 'Site C', 'is_active' => true]);
    $farmB = Farm::create(['code' => 'RHV-D', 'name' => 'Site D', 'is_active' => true]);

    Employee::factory()->create([
        'farm_id' => $farmA->id, 'user_id' => null,
        'last_name' => 'Sow', 'first_name' => 'Fatou', 'status' => 'Actif',
    ]);

    $rh = User::factory()->create(['role_id' => rhViewerRole()->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $farmB->id, 'user_id' => $rh->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($rh)->withSession(['current_farm_id' => $farmB->id])
        ->get(route('employees.index'))
        ->assertOk()
        ->assertDontSee('Sow'); // aucun rattachement ni accès au site D
});
