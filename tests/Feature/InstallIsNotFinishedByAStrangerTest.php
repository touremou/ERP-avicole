<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * N'IMPORTE QUI POUVAIT DÉCLARER L'APPLICATION INSTALLÉE.
 *
 * `/install/finish` est la SEULE étape de l'assistant placée hors du groupe
 * `redirect.if.installed` — délibérément, pour que la page de confirmation reste
 * consultable une fois l'application installée.
 *
 * Mais elle ne fait pas que montrer une page : elle bascule le `.env` en
 * production (APP_ENV, APP_DEBUG) et POSE LE MARQUEUR `storage/installed`.
 *
 * Sur une instance fraîchement déployée — donc publiquement joignable, c'est
 * tout l'objet d'un assistant web — n'importe quel visiteur pouvait appeler
 * cette URL directement et marquer l'application « installée » : sans base
 * configurée, sans migrations, sans compte administrateur.
 *
 * ─── ET LE MARQUEUR REFERME LA PORTE DERRIÈRE LUI ───
 *
 * Une fois posé, `redirect.if.installed` renvoie tout l'assistant vers `/login`
 * — où aucun compte n'existe. L'installateur légitime est verrouillé dehors, et
 * il faut aller supprimer `storage/installed` sur le serveur pour s'en sortir.
 *
 * Ce n'est donc pas une simple écriture non gardée : c'est un déni
 * d'installation, déclenchable par une requête GET anonyme, sur la seule
 * fenêtre où l'application est ouverte à tous.
 *
 * ─── LA RÈGLE ───
 *
 * La finalisation exige que l'installation soit RÉELLEMENT allée jusqu'au bout.
 * Le témoin est le compte administrateur créé par l'étape précédente
 * (`storeAdmin`, qui redirige ici) : il n'existe que si la base est configurée,
 * migrée et peuplée. Une installation sans personne pour se connecter n'est pas
 * une installation terminée.
 */

beforeEach(function () {
    /*
     * CE TEST TOUCHE À DEUX FICHIERS RÉELS — `.env` et `storage/installed`.
     * On les sauvegarde et on les restaure quoi qu'il arrive : un test qui
     * laisserait le marqueur derrière lui rendrait l'assistant inaccessible
     * pour tous les suivants, et une bascule d'`.env` en production casserait
     * l'environnement de développement. C'est précisément le dégât qu'on
     * décrit ; on ne va pas le provoquer pour le prouver.
     */
    $this->marqueur = storage_path('installed');
    $this->envPath  = base_path('.env');

    $this->marqueurExistait = File::exists($this->marqueur);
    $this->envSauvegarde    = File::exists($this->envPath) ? File::get($this->envPath) : null;
});

afterEach(function () {
    if (! $this->marqueurExistait && File::exists($this->marqueur)) {
        File::delete($this->marqueur);
    }

    if ($this->envSauvegarde !== null) {
        File::put($this->envPath, $this->envSauvegarde);
    }
});

/** Crée le compte administrateur que l'étape précédente aurait posé. */
function administrateurInstalle(): User
{
    $role = Role::firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Administrateur', 'label' => 'Administrateur',
         'icon' => '👑', 'permissions' => ['L', 'C', 'M', 'S']],
    );

    return User::factory()->create(['role_id' => $role->id]);
}

test('un inconnu ne peut pas déclarer l’installation terminée', function () {
    /*
     * LE défaut : une base vide, aucun administrateur — et un simple GET
     * anonyme posait le marqueur et basculait le `.env` en production.
     */
    expect(File::exists($this->marqueur))->toBeFalse();

    $this->get(route('install.finish'))
        ->assertRedirect(route('install.welcome'))
        ->assertSessionHas('error');

    expect(File::exists($this->marqueur))->toBeFalse();
});

test('l’assistant reste praticable après la tentative', function () {
    /*
     * LA conséquence qui fait mal, et la vraie raison de corriger : le marqueur
     * referme l'assistant derrière lui. Si la tentative avait abouti,
     * `redirect.if.installed` renverrait l'installateur légitime vers /login,
     * où aucun compte n'existe.
     */
    $this->get(route('install.finish'));

    $this->get(route('install.welcome'))->assertOk();
});

test('l’installation MENÉE À SON TERME se finalise — non-régression', function () {
    /*
     * LA borne : on ferme un raccourci, il ne faut pas fermer le chemin. Une
     * fois l'administrateur créé — ce que fait l'étape précédente, qui redirige
     * ici — la finalisation doit aboutir.
     */
    administrateurInstalle();

    $this->get(route('install.finish'))->assertOk();

    expect(File::exists($this->marqueur))->toBeTrue();
});

test('la page de confirmation reste consultable une fois installé — non-régression', function () {
    /*
     * La raison même pour laquelle cette étape est hors du groupe gardé : elle
     * doit rester atteignable après coup, et elle le dit à la vue
     * ($alreadyInstalled). Ce comportement ne doit pas bouger.
     */
    File::put($this->marqueur, now()->toDateTimeString());

    $this->get(route('install.finish'))->assertOk();
});

test('un rôle admin SANS aucun compte ne suffit pas', function () {
    /*
     * La borne du témoin choisi : c'est un compte qui peut SE CONNECTER qui fait
     * foi, pas la simple existence du rôle. Une base migrée mais vide n'est pas
     * une installation terminée.
     */
    Role::firstOrCreate(
        ['name' => 'admin'],
        ['display_name' => 'Administrateur', 'label' => 'Administrateur',
         'icon' => '👑', 'permissions' => ['L', 'C', 'M', 'S']],
    );

    $this->get(route('install.finish'))->assertRedirect(route('install.welcome'));

    expect(File::exists($this->marqueur))->toBeFalse();
});
