<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * SUSPENDRE UN COMPTE NE LUI COUPAIT RIEN.
 *
 * `is_active` n'est vérifié qu'à la CONNEXION — deux endroits, et deux
 * seulement : `LoginRequest` pour le web, `Api\AuthController` pour le mobile.
 * Aucun middleware ne le revérifie ensuite : la pile web empile
 * `EnsureAppIsInstalled`, `SetCurrentFarm`, `SetUserLocale`, `EnsureLicensed`,
 * et rien d'autre.
 *
 * Et aucun geste d'administration ne révoque de jeton : `toggleActive`,
 * `destroy` et `resetPassword` écrivent en base et purgent le cache RBAC — mais
 * `tokens()->delete()` n'apparaît nulle part dans `app/`.
 *
 * ─── CE QUE ÇA DONNE, MESURÉ ───
 *
 * Le technicien licencié garde son téléphone appairé. L'administrateur clique
 * « Suspendre » — l'écran répond « Accès de X suspendu. » Le `Bearer` déjà émis
 * continue pourtant de répondre : lecture des référentiels, écriture par la file
 * de synchronisation. Même chose après une SUPPRESSION du compte, et même chose
 * après une réinitialisation de mot de passe — le jeton ne dépend pas du mot de
 * passe.
 *
 * Sur le web, la session ouverte survit de la même façon jusqu'à expiration du
 * cookie.
 *
 * Purger `rbac_perms_{id}` ne sert à rien ici : cela force à relire une matrice
 * de droits dont le compte n'a plus le droit de se servir du tout.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * Un compte suspendu ou supprimé n'a plus d'accès, tout de suite, sur les deux
 * supports. Deux moyens complémentaires, et il faut les deux :
 *
 *   • les jetons sont RÉVOQUÉS au moment du geste — sinon un appareil déjà
 *     appairé garde une clé valide même après le retour du compte à l'état
 *     actif, ce qui n'est pas ce qu'on veut d'une suspension ;
 *   • `is_active` est revérifié à CHAQUE requête authentifiée — sinon la session
 *     web ouverte, qui ne porte aucun jeton, survivrait quand même.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Un agent de terrain : compte actif, fiche employé, accès à la ferme. */
function agentDeTerrain(Farm $ferme, string $nomDuRole = 'ouvrier_jeton'): User
{
    $role = Role::firstOrCreate(
        ['name' => $nomDuRole],
        ['display_name' => 'Ouvrier', 'label' => 'Ouvrier', 'icon' => '👷', 'permissions' => ['L']],
    );

    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            [
                'can_read' => true, 'can_create' => true,
                'can_modify' => false, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    Cache::flush();

    $compte = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

    DB::table('farm_user')->insert([
        'farm_id' => $ferme->id, 'user_id' => $compte->id,
        'is_default' => true, 'is_owner' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Employee::factory()->create(['farm_id' => $ferme->id, 'user_id' => $compte->id]);

    return $compte;
}

test('le téléphone d’un compte SUSPENDU ne lit plus rien', function () {
    /*
     * LE défaut : le jeton déjà émis continuait de répondre.
     */
    $agent = agentDeTerrain($this->farm);

    // Le décor, vérifié : avant suspension, l'appareil lit.
    Sanctum::actingAs($agent);
    $this->getJson('/api/v1/sync/pull')->assertOk();

    $this->actingAs($this->adminUser)
        ->patch(route('users.toggle_active', $agent->id))
        ->assertRedirect();

    expect($agent->fresh()->is_active)->toBeFalse();   // le geste a bien eu lieu

    Sanctum::actingAs($agent->fresh());
    $this->getJson('/api/v1/sync/pull')->assertUnauthorized();
});

test('et n’écrit plus rien par la file de synchronisation', function () {
    /*
     * Lire est déjà grave ; écrire l'est davantage. La porte d'écriture du
     * terrain doit se fermer du même geste.
     */
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton2');

    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $agent->id));

    Sanctum::actingAs($agent->fresh());

    $this->postJson('/api/v1/sync/push', ['operations' => []])->assertUnauthorized();
});

test('la session WEB ouverte est coupée elle aussi', function () {
    /*
     * Un jeton révoqué ne suffit pas : une session de navigateur n'en porte
     * aucun. Sans revérification à chaque requête, l'employé suspendu continuait
     * de travailler dans l'onglet resté ouvert jusqu'à expiration du cookie.
     */
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton3');

    // Il est connecté et travaille.
    $this->actingAs($agent)->get(route('dashboard'))->assertOk();

    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $agent->id));

    $this->actingAs($agent->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('un compte SUPPRIMÉ perd aussi ses jetons', function () {
    /*
     * Révoquer un accès doit valoir au moins autant que le suspendre.
     *
     * Le jeton est créé POUR DE BON : `Sanctum::actingAs()` n'en inscrit aucun
     * en base, et un test bâti dessus compterait zéro avant comme après — vert
     * sans rien mesurer.
     */
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton4');

    $agent->createToken('telephone');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(1);

    $this->actingAs($this->adminUser)->delete(route('users.destroy', $agent->id));

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(0);
});

test('réinitialiser le mot de passe coupe les appareils déjà appairés', function () {
    /*
     * Un jeton ne dépend pas du mot de passe : sans révocation, changer le mot
     * de passe d'un compte compromis laissait l'appareil de l'intrus connecté.
     * C'est la raison même pour laquelle on réinitialise.
     */
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton5');

    $agent->createToken('telephone');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(1);

    $this->actingAs($this->adminUser)->put(route('users.reset_password', $agent->id), [
        'password'              => 'NouveauMotDePasse!2026',
        'password_confirmation' => 'NouveauMotDePasse!2026',
    ]);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(0);
});

test('suspendre RÉVOQUE les jetons, et réactiver ne les rend pas', function () {
    /*
     * Le middleware suffirait à bloquer un compte suspendu : ce test existe pour
     * l'effet PROPRE de la révocation, que le middleware ne donne pas.
     *
     * Sans elle, un appareil appairé avant la suspension redeviendrait
     * silencieusement opérationnel à la réactivation, avec la clé qu'il détenait
     * pendant toute la suspension. Un jeton révoqué l'est pour de bon : le
     * téléphone doit se ré-appairer, et c'est ce qu'on attend d'une révocation.
     *
     * C'est aussi de la défense en profondeur : si le middleware venait à sauter
     * d'une pile, la clé du terrain resterait morte.
     */
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton8');

    $agent->createToken('telephone');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(1);

    // Suspension.
    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $agent->id));

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(0);

    // Réactivation : le compte revient, la clé de l'appareil non.
    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $agent->id));

    expect($agent->fresh()->is_active)->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $agent->id)->count())->toBe(0);
});

test('RÉACTIVER un compte lui rend l’accès — non-régression', function () {
    /*
     * LA borne : une suspension se lève. Le compte réactivé doit pouvoir se
     * reconnecter et travailler — l'appareil devra simplement se ré-appairer,
     * ce qui est le propre d'une révocation de jeton.
     */
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton6');

    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $agent->id));
    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $agent->id));

    expect($agent->fresh()->is_active)->toBeTrue();

    $this->actingAs($agent->fresh())->get(route('dashboard'))->assertOk();
});

test('un compte ACTIF n’est jamais gêné — non-régression', function () {
    // Le cas de très loin le plus courant : rien ne change pour lui.
    $agent = agentDeTerrain($this->farm, 'ouvrier_jeton7');

    Sanctum::actingAs($agent);
    $this->getJson('/api/v1/sync/pull')->assertOk();

    $this->actingAs($agent)->get(route('dashboard'))->assertOk();
});
