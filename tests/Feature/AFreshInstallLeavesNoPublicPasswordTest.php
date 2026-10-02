<?php

use App\Models\User;
use App\Support\InstallationState;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * UNE INSTALLATION NEUVE, DE BOUT EN BOUT — SUR LE VRAI SEMEUR.
 *
 * L'étape 3 de l'assistant lance `db:seed`. Ce semeur crée SIX comptes au mot
 * de passe « password » : deux dans `DatabaseSeeder` (`admin@admin.com`,
 * `user@users.com`), quatre dans `UserSeeder` (`*@avismart.com`). Mesuré, avant
 * correction, en rejouant exactement le parcours de l'assistant :
 *
 *   • l'assistant se FERMAIT à l'étape 4 — `/install/admin` renvoyait vers
 *     `/login`. Le filet « administrateur réel » de #390 ne connaissait que la
 *     liste de `UserSeeder` ; il prenait `admin@admin.com` pour un vrai
 *     administrateur. Une installation neuve ne pouvait plus se terminer.
 *     Régression de mon fait : mon test de borne posait un compte de
 *     démonstration à la main au lieu de lancer ce semeur ;
 *
 *   • même avant #390, l'installation terminée laissait CINQ comptes au mot
 *     de passe public, dont `admin@avismart.com`, ADMINISTRATEUR. L'étape 4 en
 *     reprenait un, en supprimait un autre si une case restait cochée.
 *
 * Tout ce fichier part donc du VRAI semeur. C'est la leçon : une borne éprouvée
 * sur un décor qui n'est pas le vrai chemin ne garde rien.
 */

afterEach(function () {
    File::delete(InstallationState::fichierMarqueur());
});

/** L'étape 3 de l'assistant, telle quelle. */
function etape3DeLAssistant(object $test): void
{
    $test->seed(DatabaseSeeder::class);
}

/** L'étape 4, avec le vrai administrateur de l'exploitation. */
function etape4DeLAssistant(object $test, string $adresse = 'patron@ferme.gn')
{
    return $test->post(route('install.admin.store'), [
        'company_name'                => 'Ferme du Patron',
        'admin_name'                  => 'Patron',
        'admin_email'                 => $adresse,
        'admin_password'              => 'MotDePasseSolide123',
        'admin_password_confirmation' => 'MotDePasseSolide123',
    ]);
}

/**
 * Les comptes qui s'ouvrent encore avec le mot de passe publié.
 *
 * Le mot de passe est écrit EN CLAIR, et non lu sur `UserSeeder` : c'est la
 * valeur publiée dans le dépôt, que n'importe qui peut essayer. La lire sur une
 * constante faisait aussi PLANTER ce test sur l'ancien code (constante absente)
 * — il y « échouait », mais sans rien détecter.
 */
function comptesAuMotDePassePublic(): array
{
    return User::all()
        ->filter(fn ($u) => Hash::check('password', $u->password))
        ->pluck('email')->values()->all();
}

test('après l’étape 3, l’assistant reste OUVERT — la borne qui avait cédé', function () {
    etape3DeLAssistant($this);

    expect(InstallationState::estInstallee())->toBeFalse();

    $this->get(route('install.admin'))->assertOk();
});

test('l’étape 4 crée le vrai administrateur, qui peut se connecter', function () {
    etape3DeLAssistant($this);
    etape4DeLAssistant($this)->assertRedirect(route('install.finish'));

    $patron = User::where('email', 'patron@ferme.gn')->first();

    expect($patron)->not->toBeNull()
        ->and($patron->role?->name ?? \App\Models\Role::find($patron->role_id)?->name)->toBe('admin')
        ->and(Hash::check('MotDePasseSolide123', $patron->password))->toBeTrue();
});

test('et AUCUN compte ne s’ouvre plus avec le mot de passe publié', function () {
    /*
     * LE défaut. Avant correction : cinq comptes, dont un administrateur.
     */
    etape3DeLAssistant($this);

    expect(comptesAuMotDePassePublic())->toHaveCount(6);   // le semeur, tel qu'il est

    etape4DeLAssistant($this);

    expect(comptesAuMotDePassePublic())->toBe([])
        ->and(User::whereIn('email', UserSeeder::emailsDeDemonstration())->exists())->toBeFalse();
});

test('une adresse de démonstration ne peut pas devenir celle de l’administrateur', function () {
    /*
     * Elle est publique, avec son mot de passe. La réutiliser ferait du vrai
     * compte un compte de démonstration aux yeux de toutes les gardes.
     */
    etape3DeLAssistant($this);

    etape4DeLAssistant($this, 'admin@admin.com')->assertSessionHasErrors('admin_email');

    expect(User::where('email', 'admin@admin.com')->first()?->name)->toBe('Admin AviSmart');
});

test('la liste des comptes de démonstration couvre TOUT ce que le semeur crée', function () {
    /*
     * La garde qui empêche la régression de revenir. Elle n'énumère rien :
     * elle lance le vrai semeur et exige que chaque compte créé soit déclaré.
     * Un compte semé ailleurs, demain, la fera échouer.
     */
    etape3DeLAssistant($this);

    $semes = User::pluck('email')->all();

    expect(array_values(array_diff($semes, UserSeeder::emailsDeDemonstration())))->toBe([]);
});

test('le diagnostic SIGNALE une installation ancienne qui les porte encore', function () {
    /*
     * Pour les installations faites AVANT ce correctif : l'assistant corrigé
     * protège les suivantes, pas celles qui tournent déjà.
     */
    etape3DeLAssistant($this);
    InstallationState::marquerInstallee();   // une installation en service

    Artisan::call('avismart:diagnostic');
    $ligne = collect(explode("\n", Artisan::output()))
        ->first(fn ($l) => str_contains($l, 'mot de passe public « password »'));

    expect($ligne)->not->toBeNull()
        ->and($ligne)->toContain('BLOQUANT')
        ->and($ligne)->toContain('admin@avismart.com');
});

test('un compte de démonstration au mot de passe CHANGÉ n’est pas signalé — la borne', function () {
    // Gardé délibérément, avec un vrai mot de passe : ce n'est plus un risque.
    etape3DeLAssistant($this);
    InstallationState::marquerInstallee();

    User::whereIn('email', UserSeeder::emailsDeDemonstration())
        ->update(['password' => Hash::make('un-vrai-mot-de-passe')]);

    Artisan::call('avismart:diagnostic');

    expect(Artisan::output())->toContain('Aucun compte de démonstration au mot de passe public');
});
