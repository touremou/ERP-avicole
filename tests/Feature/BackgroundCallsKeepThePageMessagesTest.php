<?php

use App\Models\RawMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN APPEL D'ARRIÈRE-PLAN MANGEAIT LES MESSAGES DE LA PAGE.
 *
 * Rejoué dans Chromium : un formulaire refusé, puis `GET /api/offline/batches`
 * — lancé par la page précédente pour rafraîchir le mode terrain — passait
 * AVANT la page de retour. Il lisait la session, et le message flashé
 * (« Saisie refusée », la confirmation d'un enregistrement) disparaissait avec
 * lui : la page revenait muette.
 *
 * Et une saisie refusée dans une fenêtre modale (laboratoire, machines…)
 * revenait fenêtre fermée, tout à ressaisir.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
    session(['current_farm_id' => $this->farm->id]);

    $this->soja = RawMaterial::create([
        'name' => 'Tourteau de soja', 'unit' => 'kg', 'stock_qty' => 0, 'unit_cost' => 6000,
        'alert_threshold' => 10, 'is_active' => true, 'energy_kcal' => 2400, 'protein_rate' => 46,
    ]);
});

/** Une analyse refusée (protéine > 100 %), envoyée depuis la fenêtre du labo. */
function analyseRefusee(RawMaterial $matiere): array
{
    return [
        'energy_kcal' => 2400, 'protein_rate' => 150,
        '_modale' => 'modalLabo', '_action' => route('raw-materials.nutrition', $matiere->id),
    ];
}

/**
 * Rejoue le cycle de vie RÉEL d'un message flashé : chaque requête démarre la
 * session, puis l'enregistre en vieillissant les messages. (Le client HTTP des
 * tests garde la session en mémoire d'une requête à l'autre et ne reproduit
 * pas ce vieillissement ; le navigateur, si — d'où ce rejeu explicite.)
 */
function requete(\Illuminate\Session\Store $session, bool $arrierePlan): void
{
    $request = \Illuminate\Http\Request::create('/api/offline/batches', 'GET', server: $arrierePlan
        ? ['HTTP_ACCEPT' => 'application/json', 'HTTP_SEC_FETCH_MODE' => 'cors']
        : ['HTTP_ACCEPT' => 'text/html', 'HTTP_SEC_FETCH_MODE' => 'navigate']);
    $request->setLaravelSession($session);

    (new \App\Http\Middleware\KeepFlashForBackgroundRequests)->handle($request, fn () => response('ok'));

    $session->save();   // vieillit les messages, comme StartSession
}

test('le message d’un refus survit à un appel d’arrière-plan', function () {
    $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
    $session->start();

    // La requête refusée flashe son message, puis redirige.
    $session->flash('error', 'Saisie refusée');
    $session->save();

    // Le rafraîchissement du mode terrain passe AVANT la page de retour.
    requete($session, arrierePlan: true);
    expect($session->get('error'))->toBe('Saisie refusée');

    // La page de retour l'affiche… puis il disparaît, comme il se doit.
    requete($session, arrierePlan: false);
    expect($session->get('error'))->toBeNull();
});

test('SANS la règle, l’appel d’arrière-plan consommait le message', function () {
    $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
    $session->start();
    $session->flash('error', 'Saisie refusée');
    $session->save();

    // Une requête ordinaire (navigation) entre-temps : le message est lu et consommé.
    requete($session, arrierePlan: false);
    expect($session->get('error'))->toBeNull();
});

test('la fenêtre de la saisie refusée se rouvre, adresse et valeurs restaurées', function () {
    $page = $this->from(route('raw-materials.index'))
        ->followingRedirects()
        ->put(route('raw-materials.nutrition', $this->soja->id), analyseRefusee($this->soja))
        ->assertOk()->getContent();

    expect($page)->toContain('window.AVISMART_MODALE')
        ->and($page)->toContain('modalLabo')
        ->and($page)->toContain('<div id="modalLabo" data-modale');
});

test('sans erreur, aucune fenêtre n’est rouverte', function () {
    $page = $this->get(route('raw-materials.index'))->assertOk()->getContent();

    expect($page)->not->toContain('window.AVISMART_MODALE');
});

test('une navigation consomme le message normalement (il ne s’affiche qu’une fois)', function () {
    $this->from(route('raw-materials.index'))
        ->put(route('raw-materials.nutrition', $this->soja->id), analyseRefusee($this->soja));

    $this->get(route('raw-materials.index'))->assertSee('Saisie Refusée');
    $this->get(route('raw-materials.index'))->assertDontSee('Saisie Refusée');
});
