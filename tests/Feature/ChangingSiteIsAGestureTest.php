<?php

use App\Models\Employee;
use App\Models\Farm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * N'IMPORTE QUEL FORMULAIRE PORTANT UN CHAMP `farm_id` DÉPLAÇAIT L'UTILISATEUR.
 *
 * `SetCurrentFarm` contenait un « switch de ferme via URL (?farm_id=X) ». Mais
 * `$request->has()` / `input()` lisent la requête ENTIÈRE — la chaîne de requête
 * ET LE CORPS. Tout formulaire portant un champ `farm_id` basculait donc le site
 * courant, en silence, AVANT même que le contrôleur ne s'exécute.
 *
 * Deux formulaires de l'application en portent un, et c'est le site de
 * DESTINATION qu'ils nomment : « Muter vers un autre site » et « Mettre à
 * disposition ».
 *
 * Mesuré : un administrateur sur le site 2 soumet une mutation vers le site 3 en
 * oubliant la date. La mutation est REFUSÉE, l'agent ne bouge pas — et le site
 * courant passe quand même à 3. Tous les écrans suivants — lots, stock, ventes,
 * tableaux de bord — montrent l'autre site, à la suite d'une action que
 * l'application vient de refuser.
 *
 * ─── ET C'ÉTAIT LA MOINS-DISANTE DE DEUX DÉCLARATIONS ───
 *
 * `FarmController::switchFarm` — la route dédiée `farms.switch`, celle que les
 * DEUX sélecteurs de l'interface utilisent — porte la même règle en mieux : elle
 * vérifie le rattachement, PUIS `Farm::isUsable()`, et refuse avec un message.
 *
 * Ce second contrôle avait été ajouté exprès, parce qu'« on pouvait basculer
 * dans un site DÉSACTIVÉ ou même SUPPRIMÉ : la session le retenait, l'en-tête le
 * nommait, et toutes les saisies y allaient ». Le bloc du middleware l'ignorait :
 * un `farm_id` sur un site désactivé y basculait quand même, rouvrant
 * exactement le trou refermé à côté.
 *
 * ─── LA RÈGLE ───
 *
 * Changer de site est un GESTE, pas un effet de bord. Il passe par la route
 * dédiée, et par elle seule. Aucune vue, aucune route de l'application ne
 * fabriquait d'URL `?farm_id=` : ce bloc n'avait pas d'appelant légitime,
 * seulement des victimes.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->siteB = Farm::create([
        'code' => 'SITE-B', 'name' => 'Site Kindia', 'is_active' => true,
    ]);

    foreach ([$this->farm->id, $this->siteB->id] as $siteId) {
        DB::table('farm_user')->updateOrInsert(
            ['farm_id' => $siteId, 'user_id' => $this->adminUser->id],
            [
                'is_default' => $siteId === $this->farm->id,
                'is_owner'   => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
    }

    session(['current_farm_id' => $this->farm->id]);
});

test('une MUTATION REFUSÉE ne déplace pas l’utilisateur', function () {
    /*
     * LE défaut, dans sa forme la plus nette : l'application refuse l'action, et
     * l'utilisateur se retrouve quand même sur l'autre site.
     */
    $agent = Employee::factory()->create(['farm_id' => $this->farm->id]);

    $this->post(route('employees.transfer', $agent), [
        'farm_id' => $this->siteB->id,      // le site de DESTINATION
        'reason'  => 'Essai',
    ])->assertSessionHasErrors('start_date');

    expect(session('current_farm_id'))->toBe($this->farm->id)
        ->and($agent->fresh()->farm_id)->toBe($this->farm->id);
});

test('une MUTATION RÉUSSIE ne déplace pas l’utilisateur non plus', function () {
    /*
     * Muter un agent vers un autre site est une décision qui concerne SON
     * dossier, pas l'écran de celui qui la prend. Le responsable reste là où il
     * travaille.
     */
    $agent = Employee::factory()->create(['farm_id' => $this->farm->id]);

    $this->post(route('employees.transfer', $agent), [
        'farm_id'    => $this->siteB->id,
        'start_date' => today()->toDateString(),
        'reason'     => 'Réorganisation',
    ]);

    expect(session('current_farm_id'))->toBe($this->farm->id);
});

test('la MISE À DISPOSITION ne déplace pas l’utilisateur', function () {
    // L'autre formulaire qui nomme un site de destination.
    $agent = Employee::factory()->create(['farm_id' => $this->farm->id]);

    $this->post(route('employees.lend', $agent), [
        'farm_id'    => $this->siteB->id,
        'start_date' => today()->toDateString(),
        'end_date'   => today()->addDays(30)->toDateString(),
    ]);

    expect(session('current_farm_id'))->toBe($this->farm->id);
});

test('la route DÉDIÉE bascule bien — non-régression', function () {
    /*
     * LA borne : on retire un chemin, il ne faut pas retirer le geste. Les deux
     * sélecteurs de l'interface passent par cette route.
     */
    $this->post(route('farms.switch'), ['farm_id' => $this->siteB->id]);

    expect(session('current_farm_id'))->toBe($this->siteB->id);
});

test('la route dédiée refuse un site DÉSACTIVÉ — la règle que le raccourci ignorait', function () {
    /*
     * Le contrôle que le bloc supprimé n'avait pas. Il avait été ajouté parce
     * qu'on pouvait travailler dans un site désactivé ; le raccourci du
     * middleware le contournait.
     */
    $this->siteB->update(['is_active' => false]);

    $this->post(route('farms.switch'), ['farm_id' => $this->siteB->id]);

    expect(session('current_farm_id'))->toBe($this->farm->id);
});

test('un site SANS rattachement reste inaccessible — non-régression', function () {
    $etranger = Farm::create(['code' => 'SITE-X', 'name' => 'Site Boké', 'is_active' => true]);

    $this->post(route('farms.switch'), ['farm_id' => $etranger->id]);

    expect(session('current_farm_id'))->toBe($this->farm->id);
});

test('une session SANS site en choisit un par défaut — non-régression', function () {
    // Le reste du middleware ne doit pas avoir bougé : sans site en session, il
    // en résout un, sinon plus rien n'est cadré.
    session()->forget('current_farm_id');

    $this->get(route('dashboard'))->assertOk();

    expect(session('current_farm_id'))->not->toBeNull();
});
