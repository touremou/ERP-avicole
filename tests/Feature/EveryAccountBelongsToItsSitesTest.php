<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\Role;
use App\Models\User;
use App\Support\ProfilsMetier;
use App\Support\SitesDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CHAQUE COMPTE N'AGIT QUE SUR SES SITES.
 *
 * Question de l'exploitation : « un technicien avicole a accès aux fermes où il
 * n'est pas affecté ? ». Les comptes RATTACHÉS étaient bien bornés à leurs
 * sites ; mais aucune des trois façons de créer un accès ne rattachait le
 * compte, qui retombait sur la ferme principale — un technicien du site de
 * Kindia travaillait dans les lots de Conakry. Création, rattrapage, et plus
 * de retrait du dernier site (App\Support\SitesDuCompte).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    ProfilsMetier::installer();

    $this->kindia = Farm::create(['name' => 'Site de Kindia', 'code' => 'KIN', 'is_active' => true]);
});

function technicienSansSite(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'profil-technicien-elevage')->value('id')]);
}

test('un compte créé au bureau est rattaché aux sites choisis', function () {
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->post(route('users.store'), [
        'name' => 'Mamadou Bah', 'email' => 'mamadou@ferme.gn', 'password' => 'Secret#2026', 'password_confirmation' => 'Secret#2026',
        'role_id' => Role::where('name', 'profil-technicien-elevage')->value('id'),
        'farm_ids' => [$this->kindia->id],
    ])->assertSessionHasNoErrors();

    $compte = User::where('email', 'mamadou@ferme.gn')->first();
    expect(SitesDuCompte::nomsDesSites($compte))->toBe(['Site de Kindia']);
});

test('un accès créé depuis la fiche employé est rattaché au site de l’employé', function () {
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->kindia->id]);
    $employe = Employee::factory()->create(['farm_id' => $this->kindia->id, 'status' => 'Actif']);

    $this->post(route('employees.access.store', $employe), [
        'email' => 'agent.kindia@ferme.gn', 'role_id' => Role::where('name', 'profil-agent-terrain')->value('id'),
    ])->assertSessionHasNoErrors();

    $compte = User::where('email', 'agent.kindia@ferme.gn')->first();
    expect(SitesDuCompte::nomsDesSites($compte))->toBe(['Site de Kindia']);
});

test('rattaché à Kindia, il voit Kindia — et ne bascule pas sur la ferme principale', function () {
    $technicien = technicienSansSite();
    SitesDuCompte::rattacher($technicien, [$this->kindia->id]);

    $me = $this->actingAs($technicien, 'sanctum')
        ->withHeader('X-Farm-Id', (string) $this->farm->id)   // il demande la ferme principale
        ->getJson('/api/v1/auth/me')->assertOk()->json();

    expect($me['scope']['farm_id'])->toBe($this->kindia->id)
        ->and(collect($me['scope']['farms'])->pluck('id')->all())->toBe([$this->kindia->id]);

    $this->actingAs($technicien)->post(route('farms.switch'), ['farm_id' => $this->farm->id])
        ->assertSessionHas('error');
});

test('on ne retire pas à un compte son dernier site', function () {
    $this->actingAs($this->adminUser);
    $technicien = technicienSansSite();
    SitesDuCompte::rattacher($technicien, [$this->kindia->id]);

    // Le retirer de Kindia, son seul site : refusé — il retomberait sur la ferme principale.
    $this->post(route('farms.users', $this->kindia), ['user_ids' => [$this->adminUser->id]])
        ->assertSessionHas('error');
    expect(SitesDuCompte::nomsDesSites($technicien))->toBe(['Site de Kindia']);

    // Rattaché d'abord ailleurs, il peut quitter Kindia.
    SitesDuCompte::rattacher($technicien, [$this->farm->id]);
    $this->post(route('farms.users', $this->kindia), ['user_ids' => [$this->adminUser->id]])
        ->assertSessionHas('success');
    expect(SitesDuCompte::nomsDesSites($technicien))->toBe([$this->farm->name]);
});

test('l’écran Utilisateurs montre les sites de chacun, et qui n’en a pas', function () {
    $technicien = technicienSansSite();
    SitesDuCompte::rattacher($technicien, [$this->kindia->id]);

    $page = $this->actingAs($this->adminUser)->get(route('users.index'))->assertOk();

    expect($page->viewData('sitesParCompte')[$technicien->id])->toBe(['Site de Kindia']);
    $page->assertSee('Aucun site rattaché');
});

test('le rattrapage rattache les comptes existants à leur site, sans toucher à l’administrateur', function () {
    $agentDeKindia = technicienSansSite();
    Employee::factory()->create(['farm_id' => $this->kindia->id, 'user_id' => $agentDeKindia->id, 'status' => 'Actif']);
    $sansFiche = technicienSansSite();

    (include database_path('migrations/2026_11_05_000007_every_account_belongs_to_its_sites.php'))->up();

    expect(SitesDuCompte::nomsDesSites($agentDeKindia))->toBe(['Site de Kindia'])
        // Sans fiche : la ferme par défaut, celle où il retombait jusqu'ici.
        ->and(SitesDuCompte::nomsDesSites($sansFiche))->toBe([Farm::find(Farm::defaultId())->name])
        ->and(DB::table('farm_user')->where('user_id', $this->adminUser->id)->exists())->toBeFalse();
});

test('deux profils de plus : vétérinaire et auditeur en lecture seule', function () {
    $auditeur = User::factory()->create(['role_id' => Role::where('name', 'profil-auditeur')->value('id')]);
    expect($auditeur->canModule('tresorerie', 'L'))->toBeTrue()
        ->and($auditeur->canModule('commerce', 'C'))->toBeFalse()
        ->and($auditeur->canModule('admin', 'L'))->toBeFalse();

    $veto = User::factory()->create(['role_id' => Role::where('name', 'profil-veterinaire')->value('id')]);
    expect($veto->canModule('elevage', 'M'))->toBeTrue()
        ->and($veto->canModule('commerce', 'L'))->toBeFalse()
        ->and($veto->espaceDeTravail())->toBe('elevage');
});
