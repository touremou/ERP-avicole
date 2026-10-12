<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « L'APPLICATION SE DÉCONNECTE QUAND J'APPUIE SUR ENTRÉE. »
 *
 * Signalé par l'exploitation. Rejoué dans Chromium sur les 323 pages de
 * l'application, la touche Entrée ne déconnecte nulle part par elle-même. Ce
 * qui reproduit le symptôme : une session TERMINÉE (inactivité) — et la
 * touche Entrée dans un champ de recherche ou de filtre, le geste où on la
 * presse le plus. La requête partait, la session n'existait plus, et l'écran
 * de connexion s'affichait SANS UN MOT. Sur un formulaire (POST), c'était
 * l'écran brut « 419 Page Expired », et la saisie perdue sans explication.
 *
 * Désormais l'écran de connexion dit pourquoi, la reconnexion ramène à la
 * page, et un jeton périmé pendant la session renvoie au formulaire avec la
 * saisie, au lieu d'un écran d'erreur.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Une porte qui échoue comme un formulaire au jeton périmé. (Le contrôle
    // CSRF est neutralisé en test ; on lève ce qu'il lèverait.)
    Route::middleware('web')->post('/_essai/jeton-perime', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
});

test('une session terminée : la connexion dit pourquoi, et ramène à la page', function () {
    $page = $this->followingRedirects()->get('/clients?search=dupont')->assertOk()->getContent();

    expect($page)->toContain(__('Votre session a pris fin. Reconnectez-vous : vous reviendrez à la page demandée.'));
    expect(session('url.intended'))->toEndWith('/clients?search=dupont');
});

test('un formulaire envoyé après la fin de session : pas d’écran 419 brut', function () {
    $this->from('/expenses/create')
        ->post('/_essai/jeton-perime', ['amount' => 1500])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __("Votre session a pris fin : la saisie n'a pas été enregistrée. Reconnectez-vous, vous reviendrez sur la page."));

    expect(session('url.intended'))->toEndWith('/expenses/create');
});

test('un jeton périmé EN session : retour au formulaire, saisie conservée', function () {
    $this->actingAs($this->adminUser)
        ->from('/expenses/create')
        ->post('/_essai/jeton-perime', ['amount' => 1500, 'description' => 'Gasoil'])
        ->assertRedirect('/expenses/create')
        ->assertSessionHasInput('description', 'Gasoil')
        ->assertSessionHas('error');
});

test('le motif d’une déconnexion forcée s’affiche sur l’écran de connexion', function () {
    $this->withSession(['error' => 'Ce compte est suspendu. Contactez un administrateur.'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('Ce compte est suspendu. Contactez un administrateur.');
});
