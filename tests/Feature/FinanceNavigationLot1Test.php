<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Lot 1 — quick wins Finance/Navigation :
 *   1. KPI dashboard « Valeur du Stock » = valorisation CMUP TOUTES catégories
 *      (l'ancien libellé « Valeur Mat. Premières » ne couvrait que Aliment & Santé).
 *   2. Retour de la Trésorerie → hub Finance (fin de la boucle de navigation).
 *   3. Centre de rapports accessible sans droit élevage (entrée transverse).
 */

function lot1Role(string $name, array $modules): Role
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
    $this->farm = Farm::firstOrCreate(['code' => 'FN-001'], ['name' => 'Ferme Nav', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);
});

test('le KPI dashboard valorise TOUT le stock (pas seulement Aliment & Santé)', function () {
    $user = User::factory()->create(['role_id' => lot1Role('gestionnaire_stock', ['logistique', 'elevage'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // 2 catégories : conso (100 × 10) + produits finis (5 × 200) = 2 000.
    Stock::create(['farm_id' => $this->farm->id, 'category' => Stock::CAT_CONSO, 'item_name' => 'Maïs', 'current_quantity' => 100, 'unit' => 'KG', 'last_unit_price' => 10, 'alert_threshold' => 0]);
    Stock::create(['farm_id' => $this->farm->id, 'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet PAC', 'current_quantity' => 5, 'unit' => 'PC', 'last_unit_price' => 200, 'alert_threshold' => 0]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Valeur du Stock', false)
        ->assertSee('2 000', false)          // total toutes catégories
        ->assertDontSee('Valeur Mat. Premières');
});

test('le Retour de la page Trésorerie remonte au hub Finance (plus de boucle)', function () {
    $user = User::factory()->create(['role_id' => lot1Role('tresorier', ['tresorerie', 'depenses'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->get(route('treasury.index'))
        ->assertOk()
        ->assertSee(route('finance.index'), false); // ancre Retour → hub Finance
});

test('un profil finance SANS droit élevage accède au centre de rapports', function () {
    $user = User::factory()->create(['role_id' => lot1Role('compta_only', ['depenses'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Centre de Rapports', false);
});

test('un profil sans AUCUN des droits (élevage/finance/admin) est rejeté des rapports', function () {
    $user = User::factory()->create(['role_id' => lot1Role('caissier_pur', ['caisse'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->get(route('reports.index'))
        ->assertRedirect(route('dashboard'));
});
