<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Logistique : un « magasinier » (logistique.L + C) peut consulter et
 * créer des articles, mais NI déplacer/ajuster un stock (M) NI le supprimer (S).
 * Vérifie aussi l'alignement des trois couches sur le mouvement de stock :
 * route, MoveStockRequest et vue exigent tous le niveau M.
 */

function logistiqueRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'logistique')->value('id');
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

    $this->magasinier = User::factory()->create(['role_id' => logistiqueRole('magasinier', ['L', 'C'])->id]);

    $this->stock = Stock::factory()->create([
        'farm_id'          => $this->farm->id,
        'category'         => 'conso',
        'item_name'        => 'Aliment démarrage RBAC',
        'unit'             => 'KG',
        'current_quantity' => 100,
    ]);
});

test('le magasinier (C) ne peut PAS déplacer/ajuster un stock (route move = M)', function () {
    $response = $this->actingAs($this->magasinier)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('stocks.move'), ['stock_id' => $this->stock->id, 'type' => 'out', 'quantity' => 10]);

    // Verrou de route can:M (aligné sur MoveStockRequest) : refus.
    expect($response->status())->toBeIn([302, 403]);
    expect($this->stock->fresh()->current_quantity)->toEqual(100.0); // rien n'a bougé
});

test('le magasinier (C) ne peut PAS supprimer un article (S)', function () {
    $this->actingAs($this->magasinier)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('stocks.destroy', $this->stock->id))
        ->assertRedirect();

    expect(Stock::whereKey($this->stock->id)->exists())->toBeTrue();
});

test("l'UI stocks ne présente NI Modifier NI le mouvement rapide au magasinier (couche vue)", function () {
    $this->actingAs($this->magasinier)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('stocks.index'))
        ->assertOk()
        ->assertSee(route('stocks.create'), false)          // C : autorisé
        ->assertDontSee(route('stocks.edit', $this->stock->id), false)  // M : masqué
        ->assertDontSee('Mouvement rapide');                // M : masqué
});

test('un manager (M) peut effectuer un mouvement de stock (3 couches alignées)', function () {
    $this->actingAs($this->managerUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('stocks.move'), ['stock_id' => $this->stock->id, 'type' => 'out', 'quantity' => 10])
        ->assertRedirect();

    // Le mouvement s'applique : route (M) + MoveStockRequest (M) + action laissent passer.
    expect($this->stock->fresh()->current_quantity)->toEqual(90.0);
});
