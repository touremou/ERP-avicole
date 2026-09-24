<?php

use App\Models\Farm;
use App\Models\License;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Services\LicenseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * L'ÉCHÉANCE D'ABONNEMENT NE S'ARRÊTAIT QU'AU BUREAU.
 *
 * `EnsureLicensed` — le verrou d'échéance — n'était posé que sur le groupe WEB.
 * Le groupe API ne portait que `EnsureAccountIsActive` et `SetUserLocale`. Et
 * `LicenseService::allowsModule()`, le seul contrôle de licence que l'API
 * appliquait, lit la liste des modules de la licence sans jamais regarder son
 * échéance : une licence échue accorde donc toujours ses modules.
 *
 * Résultat, une fois la période de grâce passée : l'écran du bureau renvoie
 * vers l'activation, et l'application terrain continue de synchroniser ventes,
 * stocks et pointages — sans terme.
 *
 * ─── L'INTENTION ÉTAIT ÉCRITE, DEUX FOIS ───
 *
 *   • `EnsureLicensed` porte une branche `api/*` → 402 qu'aucune requête ne
 *     pouvait atteindre depuis le groupe web : elle a été écrite POUR l'API ;
 *   • `ApiLicenseLockTest` l'annonce en toutes lettres : « Le paywall ne doit
 *     pas pouvoir être contourné via la PWA » — puis n'éprouve que le droit aux
 *     modules, jamais l'échéance.
 *
 * ─── CE QUE CELA CHANGE, ET CE QUE CELA NE CHANGE PAS ───
 *
 * Rien tant que la licence n'est pas ARMÉE (clé publique + enforcement) : c'est
 * l'état par défaut, et une borne ci-dessous le garde.
 *
 * Une fois armée, un téléphone qui détient des saisies hors ligne ne pourra
 * plus les pousser après la période de grâce. C'est précisément ce que la
 * grâce existe pour couvrir, et c'est dit plutôt que tu : un test l'établit.
 */

/** Licence signée, valable ce nombre de jours. */
function licenceSignee(string $cle, int $jours): string
{
    $maintenant = now();

    return LicenseService::sign([
        'v' => 1, 'id' => 'ECHEANCE', 'client' => 'Ferme', 'plan' => 'custom',
        'modules' => ['*'], 'max_users' => 0, 'max_farms' => 0, 'sms_quota' => 0,
        'iat' => $maintenant->getTimestamp(), 'nbf' => $maintenant->getTimestamp(),
        'exp' => $maintenant->copy()->addDays($jours)->getTimestamp(),
    ], $cle);
}

beforeEach(function () {
    Cache::flush();

    $cles = LicenseService::generateKeypair();
    config()->set('license.public_key', $cles['public']);
    config()->set('license.enforce', true);
    config()->set('license.grace_days', 7);

    $role = Role::firstOrCreate(
        ['name' => 'terrain_echeance'],
        ['label' => 'Terrain', 'display_name' => 'Terrain', 'permissions' => ['L', 'C', 'M']],
    );
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false,
             'created_at' => now(), 'updated_at' => now()],
        );
    }

    $this->ferme = Farm::create(['code' => 'ECH-01', 'name' => 'Ferme Échéance', 'is_active' => true]);
    $this->agent = User::factory()->create([
        'role_id' => $role->id, 'password' => Hash::make('secret-terrain'),
    ]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->ferme->id, 'user_id' => $this->agent->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(LicenseService::class)->activate('ECHEANCE', licenceSignee($cles['private'], 30));
});

/** L'appel de données le plus ordinaire du terrain. */
function tirerLesReferentiels(object $test)
{
    Sanctum::actingAs($test->agent);

    return $test->getJson('/api/v1/sync/pull');
}

test('abonnement ACTIF : le terrain synchronise — non-régression', function () {
    tirerLesReferentiels($this)->assertOk();
});

test('abonnement ÉCHU hors grâce : le terrain reçoit un 402', function () {
    /*
     * LE défaut. Avant correction, cette requête répondait 200 : le bureau
     * était verrouillé, le terrain non.
     */
    $this->travel(30 + 8)->days();

    tirerLesReferentiels($this)
        ->assertStatus(402)
        ->assertJsonPath('message', 'Abonnement expiré. Veuillez renouveler votre licence.');
});

test('et le bureau est verrouillé AU MÊME INSTANT — les deux portes disent la même chose', function () {
    /*
     * L'invariant. L'échéance est une seule règle ; elle doit tomber partout à
     * la fois, pas au bureau d'abord et au champ jamais.
     */
    $this->travel(30 + 8)->days();

    $this->actingAs($this->agent)
        ->get(route('dashboard'))
        ->assertRedirect(route('license.edit'));

    tirerLesReferentiels($this)->assertStatus(402);
});

test('en période de GRÂCE, le terrain continue — la borne', function () {
    /*
     * LA borne, et c'est elle qui rend la règle supportable : la grâce existe
     * pour laisser le temps de renouveler, et de vider les files hors ligne.
     */
    $this->travel(30 + 3)->days();

    expect(app(LicenseService::class)->status())->toBe(LicenseService::STATUS_GRACE);

    tirerLesReferentiels($this)->assertOk();
});

test('une révocation à distance tombe AUSSI sur le terrain', function () {
    // L'autre chemin vers « échu » : le client ne paie plus, la licence est
    // révoquée avant son terme. Il ne doit pas laisser le terrain ouvert non plus.
    License::query()->update(['revoked_at' => now()]);

    tirerLesReferentiels($this)->assertStatus(402);
});

test('s’authentifier reste possible, abonnement échu ou non', function () {
    /*
     * Même exemption que `login` côté web. Sans elle, l'application ne pourrait
     * pas distinguer « abonnement échu » de « mauvais mot de passe » ou de
     * « serveur injoignable » : tout lui répondrait pareil.
     */
    $this->travel(30 + 8)->days();

    $reponse = $this->postJson('/api/v1/auth/login', [
        'email'       => $this->agent->email,
        'password'    => 'secret-terrain',
        'device_name' => 'telephone',
    ]);

    expect($reponse->status())->not->toBe(402)
        ->and($reponse->status())->toBe(200);
});

test('se DÉCONNECTER reste possible — l’appareil rend son jeton', function () {
    /*
     * Même exemption que `logout` côté web. Un appareil dont l'abonnement est
     * échu doit pouvoir rendre son jeton ; le lui refuser le laisserait appairé
     * sans pouvoir rien faire, ni s'en aller.
     *
     * La connexion, elle, n'a pas besoin d'exemption : non authentifiée, elle
     * passe déjà la garde « aucun utilisateur ». Le test précédent le montre.
     */
    $this->travel(30 + 8)->days();

    $jeton = $this->agent->createToken('telephone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$jeton}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect($this->agent->tokens()->count())->toBe(0);
});

test('pousser une file hors ligne est refusé aussi — conséquence DITE, pas tue', function () {
    /*
     * Ce test n'éprouve pas une garde : il ÉTABLIT une conséquence, pour
     * qu'elle ne soit découverte par personne au pire moment. Après la grâce,
     * un téléphone qui détient des saisies non poussées ne peut plus les
     * pousser. C'est cohérent avec le verrou — c'est aussi ce qui fait de la
     * période de grâce le moment de vider les files.
     */
    $this->travel(30 + 8)->days();

    Sanctum::actingAs($this->agent);

    $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'expense.create',
        'payload' => ['uuid' => (string) Str::uuid()],
    ]]])->assertStatus(402);
});

test('licence NON ARMÉE : rien ne change, même « échue » — la borne', function () {
    /*
     * L'état par défaut de toute installation. Sans clé publique, le système de
     * licence est inactif : ce correctif ne doit rien fermer.
     */
    config()->set('license.public_key', '');

    $this->travel(30 + 8)->days();

    tirerLesReferentiels($this)->assertOk();
});
