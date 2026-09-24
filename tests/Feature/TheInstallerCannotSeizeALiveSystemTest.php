<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\InstallationState;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * L'ASSISTANT D'INSTALLATION POUVAIT REPRENDRE UN SYSTÈME EN SERVICE.
 *
 * L'assistant n'était fermé que par un fichier : `storage/installed`. Or ce
 * fichier NE VOYAGE PAS avec l'application, et c'est délibéré — le guide de
 * déploiement exclut `storage/` du rsync, et consigne même l'incident inverse
 * (« le marqueur avait été embarqué dans l'archive… exclure `storage/` »). Il
 * est posé une seule fois, sur l'hôte, à la fin du premier assistant.
 *
 * Tout ce qui reprovisionne `storage/` — nouvel hôte, conteneur reconstruit,
 * application restaurée sans son storage — efface donc le marqueur pendant que
 * la base, configurée à part dans `.env`, reste pleine.
 *
 * ─── MESURÉ, AVANT CORRECTION ───
 *
 * Base peuplée, un administrateur réel `patron@ferme.gn`. Marqueur absent. Un
 * visiteur NON AUTHENTIFIÉ poste le formulaire de l'étape 4 :
 *
 *   • e-mail du patron  → `pirate@example.com`
 *   • son mot de passe  → celui du visiteur
 *   • son ancien mot de passe ne fonctionne plus
 *   • nombre de comptes : 1
 *
 * Le compte n'a pas été DOUBLÉ, il a été REPRIS. L'exploitant ne peut plus
 * entrer chez lui, le visiteur le peut. Et les deux étapes précédentes sont du
 * même tonneau : `POST /install/database` réécrit le `.env`, `POST
 * /install/migrate` lance `migrate --force` ET `db:seed --force` sur la base de
 * production.
 *
 * ─── POURQUOI « UN ADMIN EXISTE » NE POUVAIT PAS SERVIR DE GARDE ───
 *
 * L'étape 3 lance `db:seed`, qui crée les comptes de démarrage, dont un
 * administrateur. À l'étape 4 d'une installation LÉGITIME, un administrateur
 * existe donc déjà — c'est même pour cela que `storeAdmin` est écrit pour
 * reprendre un compte plutôt que d'en créer un. Refuser là-dessus aurait
 * verrouillé toute installation neuve à son avant-dernière étape.
 *
 * ─── CE QUE LE DÉPÔT SAVAIT DÉJÀ ───
 *
 * `finish()` refuse de POSER le marqueur tant qu'aucun administrateur n'existe,
 * et son commentaire explique très bien pourquoi. La connaissance était là,
 * appliquée à une seule porte : on vérifiait la sortie, jamais l'entrée.
 */

afterEach(function () {
    // Ces tests posent parfois le vrai marqueur : on ne laisse pas traîner un
    // fichier qui fermerait l'assistant du poste de développement.
    File::delete(InstallationState::fichierMarqueur());
});

/** L'exploitation en service : un administrateur bien réel. */
function administrateurReel(string $motDePasse = 'le-vrai-mot-de-passe'): User
{
    $role = Role::firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Administrateur', 'label' => 'Administrateur', 'permissions' => ['L', 'C', 'M', 'S']],
    );

    return User::create([
        'name' => 'Patron', 'email' => 'patron@ferme.gn',
        'password' => Hash::make($motDePasse), 'role_id' => $role->id,
    ]);
}

/** Le compte que `db:seed` crée à l'étape 3 d'une installation légitime. */
function administrateurSeme(): User
{
    $role = Role::firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Administrateur', 'label' => 'Administrateur', 'permissions' => ['L', 'C', 'M', 'S']],
    );

    return User::create([
        'name' => 'Admin AviSmart', 'email' => UserSeeder::emailsDeDemonstration()[0],
        'password' => Hash::make('password'), 'role_id' => $role->id,
    ]);
}

/** Le geste du visiteur : le formulaire de l'étape 4. */
function reprendreLeCompteAdministrateur(object $test)
{
    return $test->post(route('install.admin.store'), [
        'company_name'                => 'Pirate SARL',
        'admin_name'                  => 'Pirate',
        'admin_email'                 => 'pirate@example.com',
        'admin_password'              => 'motdepasse-pirate',
        'admin_password_confirmation' => 'motdepasse-pirate',
    ]);
}

test('un visiteur anonyme ne reprend PLUS le compte administrateur', function () {
    /*
     * LE défaut, mesuré de bout en bout par la vraie route.
     */
    $patron = administrateurReel();

    reprendreLeCompteAdministrateur($this);

    expect($patron->fresh()->email)->toBe('patron@ferme.gn')
        ->and(Hash::check('motdepasse-pirate', $patron->fresh()->password))->toBeFalse();
});

test('et l’exploitant peut toujours entrer chez lui', function () {
    /*
     * L'enjeu réel : ce n'est pas la ligne en base qu'on protège, c'est
     * l'accès. Un mot de passe réécrit met l'exploitant dehors.
     */
    $patron = administrateurReel();

    reprendreLeCompteAdministrateur($this);

    expect(Hash::check('le-vrai-mot-de-passe', $patron->fresh()->password))->toBeTrue()
        ->and(User::count())->toBe(1);
});

test('l’assistant entier est fermé, pas seulement cette étape', function () {
    /*
     * Les deux étapes précédentes font des dégâts propres : réécriture du
     * `.env`, puis `migrate --force` et `db:seed --force` sur la base de
     * production. Fermer la dernière seulement n'aurait rien réglé.
     */
    administrateurReel();

    $this->get(route('install.welcome'))->assertRedirect('/login');
    $this->post(route('install.database.store'), [])->assertRedirect('/login');
    $this->post(route('install.migrate.run'), [])->assertRedirect('/login');
});

test('l’étape se garde ELLE-MÊME, middleware ou pas', function () {
    /*
     * La défense en profondeur, et ici elle n'est pas décorative : `/install/finish`
     * est DÉJÀ, délibérément, hors du groupe protégé par le middleware — pour
     * que la page de confirmation reste consultable. Une règle qui ne tient
     * qu'au middleware tient donc à ce que personne ne déplace une route de
     * plus ; celle-ci tient à l'étape elle-même.
     *
     * Ce test retire le middleware pour éprouver la seconde serrure seule.
     */
    $patron = administrateurReel();

    $this->withoutMiddleware(\App\Http\Middleware\RedirectIfInstalled::class);

    reprendreLeCompteAdministrateur($this);

    expect($patron->fresh()->email)->toBe('patron@ferme.gn')
        ->and(Hash::check('le-vrai-mot-de-passe', $patron->fresh()->password))->toBeTrue();
});

test('UNE INSTALLATION NEUVE reste possible — la borne essentielle', function () {
    /*
     * LA borne, et elle décide de tout : la garde ne doit pas supprimer la
     * fonctionnalité qu'elle protège.
     *
     * À l'étape 4 d'une installation légitime, `db:seed` a DÉJÀ créé un
     * administrateur de démonstration. Une garde posée sur « un admin existe »
     * aurait verrouillé toute installation neuve juste avant la fin.
     */
    administrateurSeme();

    $this->get(route('install.welcome'))->assertOk();

    reprendreLeCompteAdministrateur($this);

    $admin = User::where('email', 'pirate@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check('motdepasse-pirate', $admin->password))->toBeTrue();
});

test('une base VIDE laisse évidemment l’assistant s’ouvrir', function () {
    // Le tout premier démarrage : ni marqueur, ni rôle, ni compte.
    expect(InstallationState::estInstallee())->toBeFalse();

    $this->get(route('install.welcome'))->assertOk();
});

test('le marqueur EN BASE suffit — même sans le fichier', function () {
    /*
     * Le cœur du remède. C'est ce marqueur-là qui voyage avec les données
     * qu'il décrit, et qui survit au reprovisionnement de `storage/`.
     */
    Setting::set(InstallationState::CLEF, now()->toDateTimeString());

    expect(File::exists(InstallationState::fichierMarqueur()))->toBeFalse()
        ->and(InstallationState::estInstallee())->toBeTrue();

    $this->get(route('install.welcome'))->assertRedirect('/login');
});

test('le FICHIER seul suffit toujours — non-régression', function () {
    // L'ancien témoin reste valable : les hôtes en service ne changent pas de
    // comportement du jour au lendemain.
    File::put(InstallationState::fichierMarqueur(), now()->toDateTimeString());

    expect(InstallationState::estInstallee())->toBeTrue();

    $this->get(route('install.welcome'))->assertRedirect('/login');
});

test('un administrateur de DÉMONSTRATION ne ferme pas l’assistant', function () {
    /*
     * La borne du filet. Le compte semé n'est pas le témoin d'une exploitation
     * en service : c'est un décor posé par l'étape 3.
     */
    administrateurSeme();

    expect(InstallationState::administrateurReelExiste())->toBeFalse()
        ->and(InstallationState::estInstallee())->toBeFalse();
});

test('la liste des comptes semés est lue CHEZ LE SEMEUR', function () {
    /*
     * La garde qui empêche la divergence. Recopier ces adresses dans
     * l'assistant en ferait une seconde déclaration — et c'est déjà arrivé :
     * `storeAdmin` cherche « admin@admin.com » et supprime « user@users.com »,
     * deux adresses que le semeur ne crée plus depuis longtemps. Sa reprise de
     * compte ne fonctionnait que par son repli sur le rôle.
     */
    expect(UserSeeder::emailsDeDemonstration())
        ->toBe(array_keys(UserSeeder::USERS))
        ->and(UserSeeder::emailsDeDemonstration())->not->toBeEmpty();

    $source = file_get_contents(app_path('Support/InstallationState.php'));

    expect($source)->not->toContain('@avismart.com');
});

test('la finalisation pose le marqueur EN BASE, pas seulement sur disque', function () {
    /*
     * Ce que tout le remède vise : c'est ce marqueur-là qui survivra au
     * prochain reprovisionnement de `storage/`.
     *
     * Ce test garde aussi une DISTINCTION que j'avais d'abord ratée, et que la
     * suite complète m'a apprise : « cet assistant a-t-il le droit de
     * tourner ? » et « la finalisation a-t-elle déjà eu lieu ? » ne sont PAS la
     * même question. Branchée sur la première, la finalisation se croyait déjà
     * faite dès que l'étape précédente avait créé l'administrateur réel — elle
     * sautait donc la pose du marqueur ET la bascule du `.env` en production,
     * laissant `APP_DEBUG` à `true` sur une installation neuve.
     */
    administrateurReel();

    expect(InstallationState::marqueurPose())->toBeFalse();   // rien encore

    $this->get(route('install.finish'))->assertOk();

    expect(Setting::get(InstallationState::CLEF))->not->toBeNull()
        ->and(File::exists(InstallationState::fichierMarqueur()))->toBeTrue();
});

test('une base INJOIGNABLE veut dire « pas installée »', function () {
    /*
     * LA borne inverse, et elle est vitale : sur une instance neuve, `.env` ne
     * porte pas encore de connexion valable. Si une panne de base valait
     * « installée », l'assistant refuserait de s'ouvrir précisément là où il
     * est nécessaire — et il n'y aurait aucun moyen d'entrer.
     */
    Schema::drop('users');

    expect(InstallationState::estInstallee())->toBeFalse();
});
