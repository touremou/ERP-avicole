<?php

use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Role;
use App\Models\User;
use App\Support\ProfilsMetier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * PROFILS MÉTIER : UN RÔLE PAR MÉTIER, UN ESPACE DE TRAVAIL AU TERRAIN.
 *
 * Décision de l'exploitation. Les rôles livrés portaient le même niveau sur
 * tous les modules : un vendeur saisissait en élevage, un technicien voyait la
 * trésorerie, et l'application terrain montrait presque tout à presque tout le
 * monde. Des profils prêts à l'emploi s'AJOUTENT, chacun limité à ses modules,
 * avec l'espace de travail que l'application terrain met en avant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();
});

function agentAvecLeProfil(string $profil): User
{
    return User::factory()->create(['role_id' => Role::where('name', $profil)->value('id')]);
}

test('les profils métier sont installés, et une réinstallation ne refait rien', function () {
    expect(Role::whereIn('name', array_keys(ProfilsMetier::PROFILS))->count())->toBe(count(ProfilsMetier::PROFILS));

    // L'administrateur a ajusté un profil : la réinstallation respecte son travail.
    $vendeur = Role::where('name', 'profil-vendeur')->first();
    $elevage = Module::where('slug', 'elevage')->value('id');
    ModulePermission::updateOrCreate(['role_id' => $vendeur->id, 'module_id' => $elevage], ['can_read' => true]);

    expect(ProfilsMetier::installer())->toBe([])
        ->and(ModulePermission::where('role_id', $vendeur->id)->where('module_id', $elevage)->value('can_read'))->toBeTrue();
});

test('les rôles existants ne changent pas de droits', function () {
    // Le « Technicien » d'avant garde son niveau sur tous les modules.
    $manager = $this->managerUser;

    expect($manager->canModule('commerce', 'C'))->toBeTrue()
        ->and($manager->canModule('elevage', 'M'))->toBeTrue();
});

test('chaque profil est limité à ses modules', function () {
    $agent = agentAvecLeProfil('profil-agent-terrain');
    expect($agent->canModule('elevage', 'C'))->toBeTrue()
        ->and($agent->canModule('production', 'C'))->toBeTrue()
        ->and($agent->canModule('logistique', 'L'))->toBeTrue()      // l'aliment du pointage
        ->and($agent->canModule('logistique', 'C'))->toBeFalse()
        ->and($agent->canModule('commerce', 'L'))->toBeFalse()
        ->and($agent->canModule('tresorerie', 'L'))->toBeFalse();

    $vendeur = agentAvecLeProfil('profil-vendeur');
    expect($vendeur->canModule('commerce', 'C'))->toBeTrue()
        ->and($vendeur->canModule('caisse', 'M'))->toBeTrue()
        ->and($vendeur->canModule('elevage', 'L'))->toBeFalse()
        ->and($vendeur->canModule('abattoir', 'L'))->toBeFalse();

    $gerant = agentAvecLeProfil('profil-gerant');
    expect($gerant->canModule('tresorerie', 'L'))->toBeTrue()
        ->and($gerant->canModule('elevage', 'M'))->toBeTrue()
        ->and($gerant->canModule('elevage', 'S'))->toBeFalse()
        ->and($gerant->canModule('admin', 'L'))->toBeFalse();
});

test('l’application terrain reçoit l’espace de travail du profil', function () {
    $agent = agentAvecLeProfil('profil-agent-terrain');

    $me = $this->actingAs($agent, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->json();

    expect($me['role']['workspace'])->toBe('terrain')
        ->and($me['role']['workspace_label'])->toBe('Agent de terrain')
        ->and($me['permissions'])->toHaveKey('elevage')
        ->and($me['permissions'])->not->toHaveKey('commerce');
});

test('un rôle sans espace déclaré le déduit de ses droits', function () {
    expect($this->adminUser->espaceDeTravail())->toBe('direction')
        // L'ancien « Technicien », qui saisit partout : un gérant.
        ->and($this->managerUser->espaceDeTravail())->toBe('gerant');

    $caissier = Role::create(['name' => 'caisse-du-marche', 'display_name' => 'Caisse du marché', 'label' => 'Caisse du marché', 'permissions' => []]);
    ModulePermission::create(['role_id' => $caissier->id, 'module_id' => Module::where('slug', 'commerce')->value('id'),
        'can_read' => true, 'can_create' => true]);

    expect(User::factory()->create(['role_id' => $caissier->id])->espaceDeTravail())->toBe('vente');
});

test('l’administrateur choisit l’espace de travail d’un rôle, sans toucher à ses droits', function () {
    $role = Role::where('name', 'profil-magasinier')->first();
    $droits = ModulePermission::where('role_id', $role->id)->count();

    $this->actingAs($this->adminUser)
        ->patch(route('roles.update_workspace', $role), ['workspace' => 'provenderie'])
        ->assertSessionHasNoErrors();

    expect($role->fresh()->workspace)->toBe('provenderie')
        ->and(ModulePermission::where('role_id', $role->id)->count())->toBe($droits);

    $this->actingAs($this->managerUser)
        ->patch(route('roles.update_workspace', $role), ['workspace' => 'vente']);
    expect($role->fresh()->workspace)->toBe('provenderie');
});
