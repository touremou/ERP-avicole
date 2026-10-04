<?php

use App\Http\Middleware\EnsureAppIsInstalled;
use App\Models\Role;
use App\Models\User;
use App\Support\InstallationState;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * UNE BASE SEMÉE NE VAUT PAS INSTALLATION.
 *
 * `migrate --seed` crée six comptes de démonstration au mot de passe public
 * `password`, dont deux administrateurs. Au premier accès, ce middleware
 * voyait « des comptes existent », déclarait l'application installée et
 * posait le marqueur : l'assistant — qui crée l'administrateur réel et
 * supprime ces comptes (#396) — ne s'ouvrait plus jamais, et le seul moyen
 * d'entrer était `admin@avismart.com` / `password`.
 *
 * L'assistant, lui, tenait déjà cette base pour NON installée
 * (`InstallationState::estInstallee`). Ce middleware lit désormais la même
 * règle. Testé sur le VRAI semeur, et sur le middleware hors environnement de
 * test (il s'efface sinon) : la leçon de #390 est qu'une borne éprouvée sur un
 * décor ne garde rien.
 */

afterEach(fn () => File::delete(InstallationState::fichierMarqueur()));

/** Le middleware tel qu'en production (il se met en retrait sous `testing`). */
function passerLaPorte(string $chemin = '/dashboard')
{
    $env = app()['env'];
    app()['env'] = 'production';

    try {
        return app(EnsureAppIsInstalled::class)
            ->handle(Request::create($chemin), fn () => response('application'));
    } finally {
        app()['env'] = $env;
    }
}

test('après `db:seed`, l’application renvoie vers l’assistant — sans poser de marqueur', function () {
    $this->seed(DatabaseSeeder::class);
    File::delete(InstallationState::fichierMarqueur());

    $reponse = passerLaPorte();

    expect($reponse->isRedirect(route('install.welcome')))->toBeTrue('la base semée a été déclarée installée');
    expect(InstallationState::marqueurPose())->toBeFalse();
});

test('une exploitation en service (vrai administrateur) passe, et reçoit son marqueur — non-régression', function () {
    $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrateur', 'label' => 'Administrateur', 'permissions' => ['L', 'C', 'M', 'S']]);
    User::create(['name' => 'Patron', 'email' => 'patron@ferme.gn', 'password' => Hash::make('x'), 'role_id' => $role->id]);

    expect(passerLaPorte()->getContent())->toBe('application');
    expect(InstallationState::marqueurPose())->toBeTrue();
});

test('une installation déjà marquée passe, quels que soient ses comptes — non-régression', function () {
    $this->seed(DatabaseSeeder::class);
    InstallationState::marquerInstallee();

    expect(passerLaPorte()->getContent())->toBe('application');
});
