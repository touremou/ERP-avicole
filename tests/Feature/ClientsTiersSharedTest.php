<?php

use App\Models\Client;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Clients = TIERS partagés (répertoire). Un profil ANNUAIRE peut consulter,
 * créer et éditer la fiche contact d'un client — mais la partie COMMERCIALE
 * (plafond de crédit, relevé, historique de ventes, suppression) reste
 * réservée au module Commerce.
 */

function tiersRole(string $name, array $moduleLevels): Role
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

    // Annuaire seul (tiers), sans aucun droit commerce.
    $this->annuaire = User::factory()->create(['role_id' => tiersRole('annuaire_tiers', ['annuaire' => ['L', 'C', 'M']])->id]);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-TIERS-1', 'name' => 'Client Tiers',
        'type' => 'particulier', 'category' => 'detaillant', 'phone' => '620111222',
        'credit_limit' => 500000, 'status' => 'actif',
    ]);
});

test("l'annuaire (sans commerce) PEUT consulter et créer un client (répertoire tiers)", function () {
    $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.index'))->assertOk();
    $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.create'))->assertOk();

    $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('clients.store'), [
            'name' => 'Nouveau Tiers', 'type' => 'particulier', 'category' => 'detaillant', 'phone' => '620999888',
        ])->assertRedirect();

    expect(Client::where('name', 'Nouveau Tiers')->exists())->toBeTrue();
});

test("le crédit fixé par un profil annuaire est IGNORÉ (donnée commerciale)", function () {
    $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('clients.store'), [
            'name' => 'Tiers Sans Credit', 'type' => 'particulier', 'category' => 'detaillant',
            'phone' => '620777666', 'credit_limit' => 9999999, // tentative de forçage
        ])->assertRedirect();

    // Le plafond est neutralisé (0 = pas de plafond), pas 9 999 999.
    expect((float) Client::where('name', 'Tiers Sans Credit')->value('credit_limit'))->toBe(0.0);
});

test("l'annuaire ne peut NI supprimer NI consulter le relevé (réservés commerce)", function () {
    $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('clients.destroy', $this->client))->assertRedirect();
    expect(Client::whereKey($this->client->id)->exists())->toBeTrue();

    $statement = $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.statement', $this->client));
    expect($statement->status())->toBeIn([302, 403]);
});

test("la fiche client vue par l'annuaire ne divulgue PAS la situation commerciale", function () {
    $this->actingAs($this->annuaire)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.show', $this->client))->assertOk()
        ->assertSee('Client Tiers')          // fiche contact : visible
        ->assertDontSee('Total achats')      // stats commerciales : masquées
        ->assertDontSee('Plafond crédit')    // crédit : masqué
        ->assertDontSee('Dernières ventes'); // historique : masqué
});

test("un profil commerce garde l'accès complet (relevé, crédit, suppression)", function () {
    // managerUser du helper a commerce L/C/M (pas S) : relevé accessible.
    $this->actingAs($this->managerUser)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.statement', $this->client))->assertOk();
    $this->actingAs($this->managerUser)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('clients.show', $this->client))->assertOk()
        ->assertSee('Plafond crédit');
});
