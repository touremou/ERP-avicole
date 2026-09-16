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
 * ON POUVAIT S'ATTRIBUER TOUS LES DROITS À SOI-MÊME, SANS LAISSER DE TRACE.
 *
 * Deux manques distincts, et ils se renforcent.
 *
 * ─── 1. L'AUTO-ÉLÉVATION ───
 *
 * L'éditeur de la matrice exige `admin.S` — « Administration × S », le droit de
 * gérer les comptes. Il ne vérifie nulle part que le rôle édité n'est pas CELUI
 * DE L'AUTEUR :
 *
 *     ModulePermission::updateOrCreate(
 *         ['role_id' => $roleId, 'module_id' => $moduleId], [...]);
 *
 * Un « gestionnaire de comptes » à qui l'on a coché la seule case Administration
 * × S ouvre donc la matrice, coche les soixante-huit cases de SON PROPRE rôle,
 * applique — et `clearCacheForRoles` purge son cache au passage, donc l'effet est
 * IMMÉDIAT. Trésorerie, paie, prix de vente, sauvegardes, réglages : tout
 * s'ouvre, à un compte à qui l'on n'avait confié que les comptes.
 *
 * ─── 2. LE SILENCE ───
 *
 * `Role` porte le trait `AuditsChanges` ; `ModulePermission` ne le portait pas.
 * La seule écriture de la source de vérité des droits ne laissait donc AUCUNE
 * trace — ni qui, ni quand, ni quoi. Une élévation de privilèges était
 * indétectable après coup.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * On n'AUGMENTE pas les droits de son propre rôle. On peut les RÉDUIRE — se
 * retirer un droit ne présente aucun risque, et l'interdire empêcherait un
 * administrateur de se restreindre lui-même. Une augmentation vient d'un autre
 * administrateur : c'est la séparation des pouvoirs ordinaire.
 *
 * Et toute écriture de la matrice est désormais journalisée, comme l'est déjà
 * celle des rôles.
 *
 * ─── CE QUE CELA NE CHANGE PAS POUR LE SUPER-ADMINISTRATEUR ───
 *
 * Le rôle nommé « admin » passe par `Gate::before` : ses droits ne viennent pas
 * de la matrice, et sa ligne y est décorative. La règle ne lui retire donc aucun
 * pouvoir — elle borne les rôles dont les droits viennent RÉELLEMENT de la
 * matrice, c'est-à-dire ceux qu'on a voulu limiter.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/**
 * Un rôle porteur des seuls droits demandés sur le module nommé, et son
 * titulaire. Les autres modules restent vides.
 *
 * @param  list<string>  $lettres
 */
function roleCibleSur(string $nom, string $slugModule, array $lettres): array
{
    $role = Role::firstOrCreate(
        ['name' => $nom],
        ['display_name' => ucfirst($nom), 'label' => ucfirst($nom), 'icon' => '🗝️', 'permissions' => $lettres],
    );

    foreach (Module::pluck('slug', 'id') as $moduleId => $slug) {
        $actifs = $slug === $slugModule ? $lettres : [];

        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            [
                'can_read'   => in_array('L', $actifs, true),
                'can_create' => in_array('C', $actifs, true),
                'can_modify' => in_array('M', $actifs, true),
                'can_delete' => in_array('S', $actifs, true),
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    Cache::flush();

    return [$role, User::factory()->create(['role_id' => $role->id])];
}

/** La charge du formulaire : cases cochées + portée déclarée. */
function matriceAvecPortee(array $coches, array $affiches): array
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

/** Coche TOUT pour ce rôle, sur tous les modules. */
function toutCocherPour(int $roleId): array
{
    $coches = [];

    foreach (Module::pluck('id') as $moduleId) {
        $coches[$roleId][$moduleId] = ['L', 'C', 'M', 'S'];
    }

    return $coches;
}

/** Ce rôle détient-il ce droit sur ce module ? */
function detient(int $roleId, string $slugModule, string $lettre): bool
{
    $moduleId = Module::where('slug', $slugModule)->value('id');

    $colonne = ['L' => 'can_read', 'C' => 'can_create', 'M' => 'can_modify', 'S' => 'can_delete'][$lettre];

    return (bool) ModulePermission::where('role_id', $roleId)
        ->where('module_id', $moduleId)->value($colonne);
}

test('un gestionnaire de comptes ne s’attribue pas la trésorerie', function () {
    /*
     * LE défaut : la seule case « Administration × S » suffisait à tout ouvrir,
     * avec effet immédiat.
     */
    [$role, $gestionnaire] = roleCibleSur('gestionnaire_seul', 'admin', ['S']);

    expect(detient($role->id, 'tresorerie', 'S'))->toBeFalse();   // le décor, vérifié

    $this->actingAs($gestionnaire)->post(
        route('roles.update_module_matrix'),
        matriceAvecPortee(toutCocherPour($role->id), Role::pluck('id')->all()),
    );

    expect(detient($role->id, 'tresorerie', 'S'))->toBeFalse()
        ->and(detient($role->id, 'rh', 'M'))->toBeFalse();
});

test('mais il garde le droit qu’on lui avait confié — non-régression', function () {
    /*
     * LA borne : on empêche l'augmentation, on ne retire rien. Le geste refusé
     * ne doit pas déposséder son auteur de ce qu'il détenait.
     */
    [$role, $gestionnaire] = roleCibleSur('gestionnaire_seul2', 'admin', ['S']);

    $this->actingAs($gestionnaire)->post(
        route('roles.update_module_matrix'),
        matriceAvecPortee(toutCocherPour($role->id), Role::pluck('id')->all()),
    );

    expect(detient($role->id, 'admin', 'S'))->toBeTrue();
});

test('on peut en revanche se RETIRER un droit', function () {
    /*
     * Se restreindre soi-même ne présente aucun risque, et l'interdire
     * empêcherait un administrateur de réduire sa propre surface — ce qui est un
     * geste sain qu'on ne veut pas bloquer.
     */
    [$role, $gestionnaire] = roleCibleSur('gestionnaire_large', 'admin', ['L', 'C', 'M', 'S']);

    expect(detient($role->id, 'admin', 'M'))->toBeTrue();

    // Il ne garde que L et S sur l'administration.
    $moduleAdmin = Module::where('slug', 'admin')->value('id');

    $this->actingAs($gestionnaire)->post(
        route('roles.update_module_matrix'),
        matriceAvecPortee([$role->id => [$moduleAdmin => ['L', 'S']]], Role::pluck('id')->all()),
    );

    expect(detient($role->id, 'admin', 'M'))->toBeFalse()
        ->and(detient($role->id, 'admin', 'S'))->toBeTrue();
});

test('un AUTRE administrateur peut, lui, élever ce rôle — non-régression', function () {
    /*
     * C'est la séparation des pouvoirs, et non un verrou : une augmentation
     * reste possible, elle vient simplement de quelqu'un d'autre.
     */
    [$role] = roleCibleSur('gestionnaire_seul3', 'admin', ['S']);

    $this->actingAs($this->adminUser)->post(
        route('roles.update_module_matrix'),
        matriceAvecPortee(toutCocherPour($role->id), Role::pluck('id')->all()),
    );

    expect(detient($role->id, 'tresorerie', 'S'))->toBeTrue();
});

test('l’administrateur garde la main sur les AUTRES rôles — non-régression', function () {
    /*
     * La règle ne porte que sur SON PROPRE rôle. Éditer les autres reste le
     * travail ordinaire de l'éditeur de matrice.
     */
    [$role, $gestionnaire] = roleCibleSur('gestionnaire_seul4', 'admin', ['S']);
    [$autreRole]           = roleCibleSur('magasinier_cible', 'logistique', ['L']);

    $this->actingAs($gestionnaire)->post(
        route('roles.update_module_matrix'),
        matriceAvecPortee(toutCocherPour($autreRole->id), Role::pluck('id')->all()),
    );

    expect(detient($autreRole->id, 'logistique', 'S'))->toBeTrue();
});

test('toute écriture de la matrice laisse une trace', function () {
    /*
     * `Role` est audité, `ModulePermission` ne l'était pas : la seule écriture de
     * la source de vérité des droits ne laissait ni qui, ni quand, ni quoi. Une
     * élévation de privilèges était indétectable après coup.
     */
    [$role] = roleCibleSur('role_trace', 'logistique', ['L']);

    $avant = DB::table('activity_log')->count();

    $moduleLogistique = Module::where('slug', 'logistique')->value('id');

    $this->actingAs($this->adminUser)->post(
        route('roles.update_module_matrix'),
        matriceAvecPortee([$role->id => [$moduleLogistique => ['L', 'C', 'M', 'S']]], Role::pluck('id')->all()),
    );

    expect(DB::table('activity_log')->count())->toBeGreaterThan($avant);

    $trace = DB::table('activity_log')->latest('id')->first();

    expect($trace->subject_type)->toBe(ModulePermission::class)
        ->and($trace->causer_id)->toBe($this->adminUser->id);
});
