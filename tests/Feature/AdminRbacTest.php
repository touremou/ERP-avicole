<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Paramètres / Administration — le module le plus sensible. Le point
 * critique : le niveau S d'un AUTRE module (ex. commerce.S) ne doit JAMAIS
 * ouvrir l'administration (utilisateurs, matrice de droits, sauvegardes,
 * journal d'audit). Seul admin.S (ou le rôle admin) y accède.
 */

function adminTestRole(string $name, string $moduleSlug, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    $mod = Module::where('slug', $moduleSlug)->value('id');
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

    // Un « super-vendeur » : TOUS les droits (L+C+M+S) mais UNIQUEMENT sur commerce.
    // Le S de commerce ne doit pas déteindre sur l'administration.
    $this->superVendeur = User::factory()->create([
        'role_id' => adminTestRole('super_vendeur', 'commerce', ['L', 'C', 'M', 'S'])->id,
    ]);
});

test("le S d'un autre module (commerce.S) n'ouvre PAS la gestion des utilisateurs", function () {
    $response = $this->actingAs($this->superVendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('users.index'));

    expect($response->status())->toBeIn([302, 403]);
});

test("le commerce.S ne peut PAS modifier la matrice de droits des rôles", function () {
    $response = $this->actingAs($this->superVendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('roles.update_module_matrix'), ['role_id' => $this->superVendeur->role_id, 'matrix' => []]);

    expect($response->status())->toBeIn([302, 403]);
});

test("le commerce.S n'accède NI aux sauvegardes NI au journal d'audit", function () {
    $backups = $this->actingAs($this->superVendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('backups.index'));
    expect($backups->status())->toBeIn([302, 403]);

    $audit = $this->actingAs($this->superVendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('notifications.audit'));
    expect($audit->status())->toBeIn([302, 403]);
});

test("l'admin accède à la gestion des utilisateurs et aux sauvegardes", function () {
    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('users.index'))
        ->assertOk();

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('backups.index'))
        ->assertOk();
});
