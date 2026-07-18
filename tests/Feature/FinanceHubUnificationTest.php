<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Lot 2 — unification Trésorerie sous le hub Finance : un seul point d'entrée
 * (tuile « Finance »), mais le CLOISONNEMENT reste intact — un profil Trésorerie
 * seule n'accède pas aux données Dépenses, et inversement.
 */

function financeHubRole(string $name, array $modules): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => ['L']]);
    foreach ($modules as $slug) {
        $mod = Module::where('slug', $slug)->value('id');
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $mod],
            ['can_read' => true, 'can_create' => false, 'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    return $role;
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FH-001'], ['name' => 'Ferme Hub', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);
});

function attachFarm(User $user, Farm $farm): void
{
    DB::table('farm_user')->insert([
        'farm_id' => $farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('un profil Trésorerie SEULE atteint le hub Finance', function () {
    $user = User::factory()->create(['role_id' => financeHubRole('tresorier_pur', ['tresorerie'])->id]);
    attachFarm($user, $this->farm);

    $this->actingAs($user)->get(route('finance.index'))->assertOk();
});

test('un profil Dépenses SEULE atteint le hub Finance mais SANS les soldes de trésorerie', function () {
    $user = User::factory()->create(['role_id' => financeHubRole('depensier_pur', ['depenses'])->id]);
    attachFarm($user, $this->farm);

    $this->actingAs($user)->get(route('finance.index'))
        ->assertOk()
        ->assertSee('Dépenses (mois)', false)      // son périmètre
        ->assertDontSee('Soldes par compte', false); // trésorerie masquée
});

test('un profil Trésorerie seule ne voit PAS les KPI dépenses (cloisonnement)', function () {
    $user = User::factory()->create(['role_id' => financeHubRole('tresorier_pur2', ['tresorerie'])->id]);
    attachFarm($user, $this->farm);

    $this->actingAs($user)->get(route('finance.index'))
        ->assertOk()
        ->assertSee('Soldes par compte', false)    // son périmètre
        ->assertDontSee('Dettes fournisseurs', false); // dépenses masquées
});

test('un profil sans Dépenses ni Trésorerie est rejeté du hub Finance', function () {
    $user = User::factory()->create(['role_id' => financeHubRole('elevage_pur', ['elevage'])->id]);
    attachFarm($user, $this->farm);

    $this->actingAs($user)->get(route('finance.index'))
        ->assertRedirect(route('dashboard'));
});

test('le lanceur n\'affiche qu\'UNE tuile Finance (pas Dépenses + Trésorerie séparées)', function () {
    $user = User::factory()->create(['role_id' => financeHubRole('finance_complet', ['depenses', 'tresorerie'])->id]);
    attachFarm($user, $this->farm);

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    // Une seule ancre vers le hub Finance dans le lanceur desktop + mobile :
    // exactement 2 occurrences de la route (drawer + menu mobile), pas 4.
    $financeLinks = substr_count($html, route('finance.index'));
    expect($financeLinks)->toBe(2);
});
