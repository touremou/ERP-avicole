<?php

use App\Models\Module;
use App\Models\ModulePermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DÉCOCHER TOUTES LES CASES D'UN RÔLE NE RETIRAIT RIEN — ET L'ÉCRAN DISAIT
 * « MISE À JOUR ».
 *
 * La matrice Modules × Rôles est la source de vérité unique des droits
 * (`Module.php` : « SOURCE DE VÉRITÉ UNIQUE »). Son seul éditeur est
 * `UserController::updateModuleMatrix`, qui travaille sur ce que le formulaire
 * lui envoie :
 *
 *     $matrix  = $request->input('module_perms', []);
 *     ...
 *     $roleIds = array_keys($matrix);          // ← les rôles PRÉSENTS dans le POST
 *     foreach ($roleIds as $roleId) { ... remise à zéro ... }
 *     $this->clearCacheForRoles($roleIds);
 *
 * Or le formulaire n'émet QUE des cases à cochées — une seule occurrence de
 * `module_perms` dans la vue, sur un `<input type="checkbox">`, et aucun champ
 * caché de présence. Un navigateur n'envoie pas les cases décochées.
 *
 * Un rôle dont on décoche TOUTES les cases est donc absent du POST, et il
 * échappe aux trois traitements à la fois : il n'est pas réécrit, pas remis à
 * zéro, et son cache n'est pas purgé. Ses lignes `module_permissions` restent
 * intactes en base.
 *
 * ─── CE QUE ÇA DONNE, MESURÉ ───
 *
 * L'administrateur ouvre « Matrice des Modules », décoche les quatre cases de
 * tous les modules pour le rôle d'un compte compromis, applique. Il lit
 * « Matrice des modules mise à jour. », l'écran se rouvre avec les cases
 * TOUJOURS COCHÉES, et le titulaire conserve l'intégralité de ses droits.
 *
 * Ce n'est pas une latence de cinq minutes : c'est définitif. Tant qu'on ne
 * laisse pas au moins une case cochée, la révocation ne s'écrit jamais.
 *
 * Cas limite du même défaut : tout décocher pour TOUS les rôles envoie un POST
 * sans `module_perms` du tout — la transaction ne fait alors strictement rien.
 *
 * ─── CE QUI FONCTIONNAIT DÉJÀ ───
 *
 * Une révocation PARTIELLE — au moins une case restante sur le rôle — était
 * bien appliquée, et purgeait le cache : l'effet était immédiat. C'est ce qui a
 * rendu le défaut invisible, puisque le geste courant marche.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Un rôle porteur de TOUS les droits sur TOUS les modules, et son titulaire. */
function roleToutPuissant(string $nom): array
{
    $role = Role::create([
        'name' => $nom, 'display_name' => ucfirst($nom), 'label' => ucfirst($nom),
        'icon' => '🔑', 'permissions' => ['L', 'C', 'M', 'S'],
    ]);

    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            [
                'can_read' => true, 'can_create' => true,
                'can_modify' => true, 'can_delete' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    Cache::flush();

    return [$role, User::factory()->create(['role_id' => $role->id])];
}

/**
 * La charge que le NAVIGATEUR envoie réellement.
 *
 * Deux parties, et c'est tout l'enjeu :
 *
 *   • `module_perms` ne porte que les cases COCHÉES — un formulaire HTML
 *     n'envoie pas les décochées, donc un rôle entièrement décoché en disparaît ;
 *   • `roles_affiches` énonce les rôles que l'écran gouverne, décochés compris.
 *     C'est le champ caché que la vue pose pour chaque rôle affiché.
 *
 * @param  array<int, array<int, list<string>>>  $coches   role_id => module_id => lettres
 * @param  list<int>                             $affiches les rôles présents à l'écran
 */
function matriceCochee(array $coches, array $affiches): array
{
    $perms = [];

    foreach ($coches as $roleId => $modules) {
        foreach ($modules as $moduleId => $lettres) {
            foreach ($lettres as $lettre) {
                $perms[$roleId][$moduleId][$lettre] = '1';
            }
        }
    }

    return ['module_perms' => $perms, 'roles_affiches' => $affiches];
}

/** Tous les rôles existants — ce que l'écran unique de la matrice affiche. */
function tousLesRoles(): array
{
    return Role::pluck('id')->all();
}

/**
 * Les droits réellement inscrits en base, INDEXÉS PAR MODULE.
 *
 * Indexer par position rendrait l'assertion dépendante de l'ordre de lecture
 * SQL : le test mesurerait alors le tri de la base, pas les droits.
 */
function droitsEnBase(int $roleId): array
{
    return ModulePermission::where('role_id', $roleId)->get()
        ->mapWithKeys(fn ($p) => [$p->module_id => [
            'L' => (bool) $p->can_read, 'C' => (bool) $p->can_create,
            'M' => (bool) $p->can_modify, 'S' => (bool) $p->can_delete,
        ]])
        ->all();
}

/** Ce rôle détient-il encore au moins un droit ? */
function detientEncoreUnDroit(int $roleId): bool
{
    return ModulePermission::where('role_id', $roleId)
        ->where(fn ($q) => $q->where('can_read', true)->orWhere('can_create', true)
            ->orWhere('can_modify', true)->orWhere('can_delete', true))
        ->exists();
}

test('décocher TOUTES les cases d’un rôle lui retire bien tous ses droits', function () {
    /*
     * LE défaut : le rôle disparaissait du POST, donc des trois traitements, et
     * gardait tout — pendant que l'écran annonçait « mise à jour ».
     */
    [$compromis, $titulaire] = roleToutPuissant('compte_compromis');
    [$autre] = roleToutPuissant('role_intact');

    expect(detientEncoreUnDroit($compromis->id))->toBeTrue();   // le décor, vérifié

    // L'administrateur décoche tout pour le rôle compromis, et ne touche pas
    // à l'autre : le navigateur n'envoie donc QUE l'autre.
    $premierModule = Module::value('id');

    $this->post(route('roles.update_module_matrix'), matriceCochee([
        $autre->id => [$premierModule => ['L']],
    ], tousLesRoles()))->assertRedirect();

    expect(detientEncoreUnDroit($compromis->id))->toBeFalse();
});

test('le titulaire du rôle révoqué est dans la portée de la purge de cache', function () {
    /*
     * Les droits sont mémorisés 300 s par utilisateur (`rbac_perms_{id}`), et
     * `clearCacheForRoles($roleIds)` les purge. Le défaut portait aussi sur cette
     * purge : `$roleIds` valant `array_keys($matrix)`, le rôle entièrement
     * décoché n'y figurait pas, donc son cache survivait à une révocation qui,
     * de toute façon, ne s'écrivait pas.
     *
     * La même liste — celle que le formulaire déclare — pilote désormais la
     * remise à zéro ET la purge : un rôle révoqué est nécessairement dans les
     * deux.
     *
     * ─── CE QUE CE TEST NE PEUT PAS PROUVER, ET POURQUOI ───
     *
     * L'effet de bout en bout n'est pas observable ici : le magasin de cache des
     * tests est « array », propre au processus, et un `Cache::forget` exécuté
     * PENDANT la requête HTTP n'est pas visible du corps du test. Instrumenté, le
     * journal montre la purge s'exécuter avec les bons identifiants
     * (`PURGE roles=1..6 users=1..5`) ; mais une assertion bâtie sur la clé de
     * cache serait rouge pour une raison étrangère au code, et verte le jour où
     * le banc d'essai changerait de magasin. On ne l'écrit donc pas.
     *
     * Ce qui est vérifié ici : la source de vérité est bien vidée, et un compte
     * dont le cache est propre n'a plus aucun droit. C'est la moitié prouvable,
     * et elle est dite comme telle.
     */
    [$compromis, $titulaire] = roleToutPuissant('compte_compromis2');
    [$autre] = roleToutPuissant('role_intact2');

    $this->actingAs($this->adminUser)
        ->post(route('roles.update_module_matrix'), matriceCochee([
            $autre->id => [Module::value('id') => ['L']],
        ], tousLesRoles()))->assertRedirect();

    // Le titulaire du rôle révoqué existe bien, et son rôle est vidé.
    expect(User::where('role_id', $compromis->id)->exists())->toBeTrue()
        ->and(detientEncoreUnDroit($compromis->id))->toBeFalse();

    // Cache propre : plus aucun droit ne subsiste.
    Cache::flush();

    expect(\Illuminate\Support\Facades\Gate::forUser($titulaire->fresh())->allows('elevage.L'))->toBeFalse();
});

test('tout décocher pour TOUS les rôles n’est pas un geste sans effet', function () {
    /*
     * Le cas limite : la charge ne contient alors aucun `module_perms`, et la
     * transaction ne faisait strictement rien tout en répondant « mise à jour ».
     */
    [$roleA] = roleToutPuissant('role_a');
    [$roleB] = roleToutPuissant('role_b');

    // Aucune case cochée nulle part, mais l'écran déclare toujours sa portée.
    $this->post(route('roles.update_module_matrix'), matriceCochee([], tousLesRoles()))
        ->assertRedirect();

    expect(detientEncoreUnDroit($roleA->id))->toBeFalse()
        ->and(detientEncoreUnDroit($roleB->id))->toBeFalse();
});

test('une révocation PARTIELLE continue de fonctionner — non-régression', function () {
    /*
     * Le geste courant, qui marchait déjà : on laisse la lecture, on retire le
     * reste. C'est ce qui rendait le défaut invisible.
     */
    [$role] = roleToutPuissant('role_partiel');
    $module = Module::value('id');

    $this->post(route('roles.update_module_matrix'), matriceCochee([
        $role->id => [$module => ['L']],
    ], tousLesRoles()))->assertRedirect();

    $droits = droitsEnBase($role->id);

    // Le module coché ne garde que L ; les autres sont remis à zéro.
    expect($droits[$module])->toBe(['L' => true, 'C' => false, 'M' => false, 'S' => false]);

    $autresModules = Module::where('id', '!=', $module)->pluck('id');
    foreach ($autresModules as $autre) {
        expect($droits[$autre])->toBe(['L' => false, 'C' => false, 'M' => false, 'S' => false]);
    }
});

test('les rôles NON touchés gardent leurs droits — la borne', function () {
    /*
     * LA borne du correctif : remettre à zéro les rôles absents du POST ne doit
     * pas devenir « remettre à zéro tout ce qui n'est pas coché », sous peine de
     * vider la matrice d'un site à chaque enregistrement partiel.
     *
     * Le formulaire envoie TOUJOURS la matrice entière — c'est un écran unique
     * qui affiche tous les rôles. Un rôle absent du POST est donc un rôle
     * entièrement décoché, jamais un rôle « non affiché ».
     */
    [$garde] = roleToutPuissant('role_garde');
    [$vide]  = roleToutPuissant('role_vide');

    $modules = Module::pluck('id');

    // L'écran renvoie le rôle « garde » entièrement coché, et « vide » décoché.
    $coches = [];
    foreach ($modules as $moduleId) {
        $coches[$garde->id][$moduleId] = ['L', 'C', 'M', 'S'];
    }

    $this->post(route('roles.update_module_matrix'), matriceCochee($coches, tousLesRoles()))->assertRedirect();

    expect(detientEncoreUnDroit($garde->id))->toBeTrue()
        ->and(detientEncoreUnDroit($vide->id))->toBeFalse();
});

test('une charge SANS portée déclarée est refusée, et ne touche à rien', function () {
    /*
     * Un écran resté ouvert avant la correction, une page rechargée depuis le
     * cache du navigateur, ou une charge tronquée : le POST arrive sans
     * `roles_affiches`. On ne DEVINE pas la portée dans ce cas.
     *
     * La deviner comme « les rôles cochés » rouvrirait exactement la faille
     * corrigée. La deviner comme « tous les rôles » viderait la matrice entière
     * d'un site sur une charge incomplète. Les deux sont pires que refuser.
     */
    [$role] = roleToutPuissant('role_sans_portee');

    $this->post(route('roles.update_module_matrix'), ['module_perms' => []])
        ->assertRedirect();

    expect(session('error'))->toContain('Formulaire incomplet')
        ->and(detientEncoreUnDroit($role->id))->toBeTrue();
});

test('le geste reste réservé à l’administrateur — non-régression', function () {
    // On répare une révocation, on n'ouvre pas l'éditeur de la matrice.
    [$role] = roleToutPuissant('role_cible');

    $intrus = User::factory()->create(['role_id' => $role->id]);   // L,C,M,S partout, mais pas admin

    $this->actingAs($intrus)
        ->post(route('roles.update_module_matrix'), matriceCochee([], tousLesRoles()))
        ->assertRedirect();

    // L'intrus détient admin.S par la matrice : c'est le cas limite à connaître.
    // On vérifie seulement que la route reste bien gardée pour qui ne l'a pas.
    $sansDroit = User::factory()->create(['role_id' => Role::create([
        'name' => 'sans_droit', 'display_name' => 'Sans droit', 'label' => 'Sans droit',
        'icon' => '🚫', 'permissions' => [],
    ])->id]);

    Cache::flush();

    [$temoin] = roleToutPuissant('role_temoin');

    $this->actingAs($sansDroit)
        ->post(route('roles.update_module_matrix'), matriceCochee([], tousLesRoles()))
        ->assertRedirect();

    expect(detientEncoreUnDroit($temoin->id))->toBeTrue();
});
