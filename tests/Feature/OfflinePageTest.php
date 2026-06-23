<?php

/**
 * Tests Feature — Page de repli hors-ligne (PWA)
 *
 * Régression : /offline doit être PUBLIQUE. Si elle était protégée par `auth`,
 * le service worker (qui la pré-cache) ou un repli de navigation déclenché
 * déconnecté la ferait mémoriser comme URL « intended », renvoyant l'utilisateur
 * sur /offline juste après connexion avant la redirection vers le dashboard.
 */

use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

test('la page /offline est accessible sans authentification', function () {
    $this->get('/offline')
        ->assertOk()
        ->assertSee('Mode Terrain', false);
});

test('accéder à /offline déconnecté ne le mémorise pas comme URL intended', function () {
    // Un guest visite /offline (cas du pré-cache SW / repli de navigation).
    $this->get('/offline')->assertOk();

    // Aucune URL « intended » ne doit avoir été posée en session : sinon la
    // connexion renverrait l'utilisateur sur /offline.
    expect(session()->get('url.intended'))->toBeNull();

    // Et après connexion, l'utilisateur atterrit sur sa page d'accueil, jamais
    // sur /offline.
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/offline');
    $response->assertOk(); // toujours accessible, mais pas imposée au login
});
