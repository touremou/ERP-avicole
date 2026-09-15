<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * ON POUVAIT PERDRE LE DERNIER ADMINISTRATEUR ET VERROUILLER L'INSTALLATION.
 *
 * `UserController` pose deux garde-fous d'auto-destruction, et le dit :
 *
 *     if (auth()->id() === $user->id) return back()->with('error',
 *         'Impossible de suspendre votre propre compte.');
 *     if (auth()->id() === $user->id) return back()->with('error',
 *         'Impossible de supprimer votre propre accès.');
 *
 * Mais le CHANGEMENT DE RÔLE n'en a aucun — ni sur soi-même, ni sur « dernier
 * administrateur » :
 *
 *     $validated = $request->validate(['role_id' => 'required|exists:roles,id']);
 *     $user->update(['role_id' => $validated['role_id']]);
 *
 * Et `update()` écrit `role_id` dans la même passe que le nom et l'e-mail.
 *
 * Le geste est à un clic : la liste des comptes porte un menu déroulant de rôle
 * sur CHAQUE ligne, y compris la sienne, avec `onchange="this.form.submit()"`.
 * Une fausse manœuvre au clavier suffit.
 *
 * ─── CE QUE ÇA COÛTE ───
 *
 * Le super-administrateur est reconnu au NOM de son rôle
 * (`$user->userRole?->name === 'admin'`, `Gate::before`). Plus aucun compte ne
 * portant ce rôle, plus personne ne passe `admin.S` — or TOUS les chemins de
 * réparation l'exigent : gestion des comptes, création de rôles, matrice des
 * modules, réglages, sauvegardes, fermes, corbeille.
 *
 * L'installation n'est alors plus administrable que par accès SQL direct. Aucune
 * commande de secours n'existe (`routes/console.php` vérifié).
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * L'exploitation garde TOUJOURS au moins un administrateur actif. Les trois
 * gestes qui peuvent le faire disparaître — rétrograder, suspendre, supprimer —
 * refusent le dernier. Et on ne se rétrograde pas soi-même, exactement comme on
 * ne se suspend ni ne se supprime : c'est le garde-fou que le fichier déclarait
 * déjà pour les deux autres gestes.
 */

beforeEach(function () {
    $this->setUpRbac();
});

/** Un rôle ordinaire, sans aucun droit d'administration. */
function roleOrdinaire(string $nom = 'ouvrier_simple'): Role
{
    $role = Role::firstOrCreate(
        ['name' => $nom],
        ['display_name' => 'Ouvrier', 'label' => 'Ouvrier', 'icon' => '👷', 'permissions' => ['L']],
    );

    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            [
                'can_read' => true, 'can_create' => false,
                'can_modify' => false, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    Cache::flush();

    return $role;
}

/**
 * Un compte qui détient `admin.S` PAR LA MATRICE, sans porter le rôle « admin ».
 *
 * Indispensable pour éprouver les gestes de suspension et de suppression : si
 * l'acteur du test est lui-même rétrogradé, il ne passe plus `admin.S` et le
 * refus qu'on observe vient du verrou d'accès, pas de la règle du dernier
 * administrateur. Le test serait vert sans rien mesurer.
 */
function adminParLaMatrice(string $nom = 'gestionnaire_comptes'): User
{
    $role = Role::firstOrCreate(
        ['name' => $nom],
        ['display_name' => 'Gestionnaire', 'label' => 'Gestionnaire', 'icon' => '🗝️', 'permissions' => ['L', 'C', 'M', 'S']],
    );

    foreach (Module::pluck('slug', 'id') as $moduleId => $slug) {
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

    return User::factory()->create(['role_id' => $role->id]);
}

/** Combien de comptes ACTIFS peuvent encore administrer l'installation. */
function administrateursRestants(): int
{
    return User::where('is_active', true)
        ->whereHas('userRole', fn ($q) => $q->where('name', 'admin'))
        ->count();
}

test('rétrograder le DERNIER administrateur est refusé', function () {
    /*
     * LE défaut : un clic dans le menu déroulant de rôle, et plus personne ne
     * peut administrer l'installation.
     */
    $ordinaire = roleOrdinaire();

    expect(administrateursRestants())->toBe(1);   // le décor, vérifié

    // Un second compte administrateur fait le geste, pour écarter le garde-fou
    // d'auto-rétrogradation et n'éprouver que la règle du dernier.
    $autreAdmin = User::factory()->create(['role_id' => $this->adminUser->role_id]);

    $this->actingAs($autreAdmin)
        ->patch(route('users.update_role', $this->adminUser->id), ['role_id' => $ordinaire->id]);

    // Il en restait deux : celui-ci passe, il reste $autreAdmin.
    expect(administrateursRestants())->toBe(1);

    // Et le DERNIER ne peut plus être rétrogradé — par personne.
    $this->actingAs($autreAdmin)
        ->patch(route('users.update_role', $autreAdmin->id), ['role_id' => $ordinaire->id]);

    expect(administrateursRestants())->toBe(1)
        ->and($autreAdmin->fresh()->role_id)->toBe($this->adminUser->role_id);
});

test('on ne se rétrograde pas soi-même', function () {
    /*
     * Le garde-fou que le fichier déclare déjà pour suspendre et supprimer, et
     * qui manquait au changement de rôle — alors que c'est le geste le plus
     * exposé : le menu est sur la ligne de chacun, et s'envoie au `change`.
     */
    $ordinaire = roleOrdinaire('ouvrier_simple2');

    // Un second administrateur existe : ce n'est donc PAS la règle du dernier
    // qui est éprouvée ici, mais bien celle de l'auto-rétrogradation.
    User::factory()->create(['role_id' => $this->adminUser->role_id]);

    $this->actingAs($this->adminUser)
        ->patch(route('users.update_role', $this->adminUser->id), ['role_id' => $ordinaire->id]);

    expect($this->adminUser->fresh()->role_id)->toBe($this->adminUser->role_id);
});

test('l’édition du compte ne contourne pas la règle', function () {
    /*
     * `update()` écrit `role_id` dans la même passe que le nom et l'e-mail :
     * sans la même garde, la porte de devant serait fermée et celle de côté
     * ouverte.
     */
    $ordinaire = roleOrdinaire('ouvrier_simple3');

    $this->actingAs($this->adminUser)
        ->put(route('users.update', $this->adminUser->id), [
            'name'    => $this->adminUser->name,
            'email'   => $this->adminUser->email,
            'role_id' => $ordinaire->id,
        ]);

    expect(administrateursRestants())->toBe(1)
        ->and($this->adminUser->fresh()->role_id)->toBe($this->adminUser->role_id);
});

test('suspendre le dernier administrateur est refusé', function () {
    /*
     * Le garde-fou existant ne couvre que SOI-MÊME. Un second compte porteur
     * d'`admin.S` par la matrice pouvait donc suspendre le dernier vrai
     * administrateur, et se retrouver sans personne pour rouvrir.
     */
    // Un gestionnaire de comptes : il détient `admin.S` par la matrice, mais ne
    // porte pas le rôle « admin ». Il reste donc habilité APRÈS le geste, ce qui
    // est indispensable pour que le refus mesuré soit bien celui de la règle.
    $gestionnaire = adminParLaMatrice();

    expect(administrateursRestants())->toBe(1);

    $this->actingAs($gestionnaire)
        ->patch(route('users.toggle_active', $this->adminUser->id));

    expect($this->adminUser->fresh()->is_active)->toBeTrue()
        ->and(administrateursRestants())->toBe(1);
});

test('supprimer le dernier administrateur est refusé', function () {
    // Même règle, geste plus définitif encore.
    $gestionnaire = adminParLaMatrice('gestionnaire_comptes2');

    expect(administrateursRestants())->toBe(1);

    $this->actingAs($gestionnaire)
        ->delete(route('users.destroy', $this->adminUser->id));

    expect(User::whereKey($this->adminUser->id)->exists())->toBeTrue()
        ->and(administrateursRestants())->toBe(1);
});

test('avec DEUX administrateurs, tout reste possible — non-régression', function () {
    /*
     * LA borne : on protège le dernier, on ne fige pas l'administration. Tant
     * qu'il en reste un, les trois gestes fonctionnent.
     */
    $ordinaire = roleOrdinaire('ouvrier_simple6');
    $a = User::factory()->create(['role_id' => $this->adminUser->role_id]);
    $b = User::factory()->create(['role_id' => $this->adminUser->role_id]);

    expect(administrateursRestants())->toBe(3);

    $this->actingAs($this->adminUser)
        ->patch(route('users.update_role', $a->id), ['role_id' => $ordinaire->id]);

    expect($a->fresh()->role_id)->toBe($ordinaire->id)
        ->and(administrateursRestants())->toBe(2);

    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $b->id));

    expect($b->fresh()->is_active)->toBeFalse()
        ->and(administrateursRestants())->toBe(1);
});

test('changer le rôle d’un compte ORDINAIRE n’est pas gêné — non-régression', function () {
    // Le cas de très loin le plus courant : rien ne change pour lui.
    $ordinaire = roleOrdinaire('ouvrier_simple7');
    $agent = User::factory()->create(['role_id' => $ordinaire->id]);

    $autre = roleOrdinaire('magasinier_simple');

    $this->actingAs($this->adminUser)
        ->patch(route('users.update_role', $agent->id), ['role_id' => $autre->id]);

    expect($agent->fresh()->role_id)->toBe($autre->id);
});

test('un administrateur SUSPENDU ne compte plus comme administrateur', function () {
    /*
     * La règle porte sur les administrateurs qui peuvent RÉELLEMENT agir. Un
     * compte suspendu ne se connecte plus : le compter laisserait l'installation
     * verrouillée en croyant la protéger — il resterait « deux administrateurs »
     * dont un incapable d'ouvrir une session.
     *
     * Le geste est fait par un TIERS habilité par la matrice : sans cela, le
     * refus observé viendrait du garde d'auto-rétrogradation, et ce test serait
     * vert sans rien mesurer de l'exclusion des suspendus.
     */
    $adminSuspendu = User::factory()->create(['role_id' => $this->adminUser->role_id]);
    $gestionnaire  = adminParLaMatrice('gestionnaire_comptes3');
    $ordinaire     = roleOrdinaire('ouvrier_simple8');

    $this->actingAs($this->adminUser)->patch(route('users.toggle_active', $adminSuspendu->id));

    expect($adminSuspendu->fresh()->is_active)->toBeFalse()
        ->and(administrateursRestants())->toBe(1);   // le suspendu ne compte pas

    // Le tiers tente de rétrograder le DERNIER administrateur actif. Si les
    // suspendus comptaient, la règle croirait qu'il en reste deux et laisserait
    // passer — l'installation se retrouverait sans personne aux commandes.
    $this->actingAs($gestionnaire)
        ->patch(route('users.update_role', $this->adminUser->id), ['role_id' => $ordinaire->id]);

    expect(administrateursRestants())->toBe(1)
        ->and($this->adminUser->fresh()->role_id)->toBe($this->adminUser->role_id);
});
