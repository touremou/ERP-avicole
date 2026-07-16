<?php

use App\Models\Client;
use App\Models\Module;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Commerce (exemple 1 de l'audit) : un VENDEUR (commerce.L + C uniquement)
 * peut consulter et créer, mais NI valider/livrer (M) NI annuler/supprimer (S)
 * — sur les trois couches : le serveur refuse ET l'UI ne présente pas les
 * boutons d'action non autorisés.
 */

beforeEach(function () {
    $this->setUpRbac();

    $vendeur = Role::firstOrCreate(
        ['name' => 'vendeur'],
        ['label' => 'Vendeur', 'display_name' => 'Vendeur', 'permissions' => ['L', 'C']]
    );
    $commerceId = Module::where('slug', 'commerce')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $vendeur->id, 'module_id' => $commerceId],
        ['can_read' => true, 'can_create' => true, 'can_modify' => false, 'can_delete' => false,
         'created_at' => now(), 'updated_at' => now()]
    );
    $this->vendeur = User::factory()->create(['role_id' => $vendeur->id]);

    session(['current_farm_id' => $this->farm->id]);
    $this->client = Client::create([
        'farm_id'      => $this->farm->id,
        'client_id'    => 'CLI-RBAC-001',
        'name'         => 'Boutique Test RBAC',
        'type'         => 'particulier',
        'category'     => 'detaillant',
        'phone'        => '622000000',
        'credit_limit' => 0,
        'status'       => 'actif',
    ]);
    $this->sale = Sale::create([
        'farm_id'    => $this->farm->id,
        'uuid'       => (string) Str::uuid(),
        'reference'  => 'BL-TEST-0001',
        'client_id'  => $this->client->id,
        'user_id'    => $this->adminUser->id,
        'sale_date'  => now()->toDateString(),
        'type'       => 'bon_livraison',
        'status'     => 'brouillon',
        'subtotal'   => 100000,
        'total_amount' => 100000,
    ]);
});

test('le vendeur ne peut PAS valider une vente (M refusé côté serveur)', function () {
    $this->actingAs($this->vendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->put(route('sales.validate', $this->sale))
        ->assertRedirect(); // refus → redirection (jamais 200 d'exécution)

    expect($this->sale->fresh()->status)->toBe('brouillon'); // rien n'a bougé
});

test("le vendeur ne peut PAS annuler une vente (S refusé côté serveur)", function () {
    $this->actingAs($this->vendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->put(route('sales.cancel', $this->sale))
        ->assertRedirect();

    expect($this->sale->fresh()->status)->toBe('brouillon');
});

test("l'UI de la vente ne présente NI Valider NI Annuler au vendeur (couche vue)", function () {
    $response = $this->actingAs($this->vendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('sales.show', $this->sale))
        ->assertOk();

    $response->assertDontSee('Valider & Déstocker')
        ->assertDontSee(route('sales.validate', $this->sale), false)
        ->assertDontSee(route('sales.cancel', $this->sale), false);
});

test("un manager (M) voit et peut valider ; l'admin peut annuler", function () {
    // Le manager du helper a L/C/M sur tous les modules.
    $this->actingAs($this->managerUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('sales.show', $this->sale))
        ->assertOk()
        ->assertSee('Valider & Déstocker');

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->put(route('sales.cancel', $this->sale))
        ->assertRedirect();

    expect($this->sale->fresh()->status)->toBe('annule');
});

test('le vendeur ne peut pas supprimer un client (S)', function () {
    $this->actingAs($this->vendeur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('clients.destroy', $this->client))
        ->assertRedirect();

    expect(Client::whereKey($this->client->id)->exists())->toBeTrue();
});
