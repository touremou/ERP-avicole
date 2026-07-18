<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Le sélecteur de ferme (web nav + mobile Mon espace) apparaît dès qu'un
 * utilisateur — même NON admin — est affecté à ≥ 2 sites. L'affectation se
 * fait en « Gestion de site » ; la bascule elle-même n'exige aucun droit admin.
 */

function switcherRole(string $name): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => ['L']]);
    $mod = Module::where('slug', 'elevage')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => true, 'can_create' => false, 'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->farmA = Farm::create(['code' => 'SW-A', 'name' => 'Site A', 'is_active' => true]);
    $this->farmB = Farm::create(['code' => 'SW-B', 'name' => 'Site B', 'is_active' => true]);
    // Utilisateur NON admin (elevage.L seulement), affecté au site A par défaut.
    $this->user = User::factory()->create(['role_id' => switcherRole('agent_multisite')->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farmA->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('mono-site : /auth/me n\'expose qu\'une ferme → sélecteur masqué', function () {
    Sanctum::actingAs($this->user);
    $farms = $this->getJson('/api/v1/auth/me')->assertOk()->json('scope.farms');
    expect($farms)->toHaveCount(1);
});

test('affecté à un 2e site → /auth/me expose 2 fermes (sélecteur mobile visible)', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farmB->id, 'user_id' => $this->user->id,
        'is_default' => false, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->user);
    $farms = collect($this->getJson('/api/v1/auth/me')->assertOk()->json('scope.farms'));
    expect($farms)->toHaveCount(2)
        ->and($farms->pluck('name'))->toContain('Site A')->toContain('Site B');
});

test('un NON admin bascule vers un site auquel il est affecté (aucune erreur)', function () {
    DB::table('farm_user')->insert([
        'farm_id' => $this->farmB->id, 'user_id' => $this->user->id,
        'is_default' => false, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->post(route('farms.switch'), ['farm_id' => $this->farmB->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(session('current_farm_id'))->toBe($this->farmB->id);
});

test('un NON admin ne peut PAS basculer vers un site NON affecté', function () {
    $this->actingAs($this->user)
        ->from(route('dashboard'))
        ->post(route('farms.switch'), ['farm_id' => $this->farmB->id])
        ->assertSessionHas('error');

    expect(session('current_farm_id'))->not->toBe($this->farmB->id);
});
