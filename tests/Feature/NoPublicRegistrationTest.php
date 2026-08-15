<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * N'IMPORTE QUI POUVAIT SE CRÉER UN COMPTE — ET SE RETROUVAIT CONNECTÉ.
 *
 * Les routes `register` livrées avec Breeze étaient restées ouvertes. Un
 * inconnu atteignant le site pouvait poster un nom, un e-mail, un mot de passe,
 * et se retrouvait IMMÉDIATEMENT authentifié sur le tableau de bord :
 * `Auth::login($user)` suivi d'une redirection.
 *
 * Le compte naissait ACTIF (`users.is_active` vaut true par défaut) et sans
 * rôle. Les portes de modules le refusaient donc — mais il détenait une session
 * valide et durable, de quoi sonder chaque écran à la recherche d'une porte
 * oubliée, et la table des comptes se remplissait sans que personne ne l'ait
 * décidé.
 *
 * LE PLUS PARLANT : le test Breeze d'origine ATTESTAIT ce comportement.
 * `test_new_users_can_register` vérifiait que l'inconnu ressort authentifié.
 * Une suite verte peut garantir exactement ce qu'on ne veut pas — c'est
 * pourquoi il part avec la route qu'il protégeait.
 *
 * CET ERP NE S'ADRESSE PAS AU PUBLIC. Il sert une exploitation, dont les
 * comptes se créent à l'écran d'administration, derrière le droit `S`, avec un
 * rôle choisi. La porte d'inscription n'avait aucun usage — seulement un risque.
 */

test('la route d’inscription n’existe plus', function () {
    expect(Route::has('register'))->toBeFalse();
});

test('POST /register ne crée plus de compte', function () {
    // LE défaut, dans sa forme la plus directe.
    $avant = User::count();

    $this->post('/register', [
        'name' => 'Inconnu',
        'email' => 'inconnu@exemple.test',
        'password' => 'motdepasse',
        'password_confirmation' => 'motdepasse',
    ])->assertNotFound();

    expect(User::count())->toBe($avant)
        ->and(auth()->check())->toBeFalse();
});

test('GET /register ne présente plus de formulaire', function () {
    $this->get('/register')->assertNotFound();
});

test('la connexion, elle, reste ouverte', function () {
    // Non-régression : on ferme l'inscription, pas l'accès.
    $this->get('/login')->assertOk();
});

test('la connexion reste protégée contre le tâtonnement', function () {
    // Ce qui garde la porte restante : cinq essais par empreinte, puis blocage
    // (LoginRequest::ensureIsNotRateLimited). Sans lui, fermer l'inscription
    // n'aurait fait que déplacer le problème.
    $source = file_get_contents(app_path('Http/Requests/Auth/LoginRequest.php'));

    expect($source)->toContain('ensureIsNotRateLimited')
        ->and($source)->toContain('RateLimiter::tooManyAttempts');
});

test('la création de comptes reste possible pour l’administration', function () {
    // On n'a pas coupé la seule voie légitime : l'écran d'administration, avec
    // un rôle choisi, derrière le droit `S`.
    $this->setUpRbac();

    expect(Route::has('users.store'))->toBeTrue();

    $this->actingAs($this->adminUser)->get(route('users.index'))->assertOk();
});

test('aucune vue ne pointe vers une route d’inscription disparue', function () {
    // Une vue qui référence `route('register')` lèverait une exception à
    // l'affichage : retirer la route sans retirer ce qui la nomme déplacerait
    // le défaut au lieu de le corriger.
    $orphelines = [];

    foreach (glob(resource_path('views/**/*.blade.php')) + glob(resource_path('views/*.blade.php')) as $vue) {
        if (str_contains(file_get_contents($vue), "route('register')")) {
            $orphelines[] = str_replace(resource_path('views/'), '', $vue);
        }
    }

    expect($orphelines)->toBe([]);
});
