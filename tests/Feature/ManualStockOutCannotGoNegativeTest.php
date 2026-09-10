<?php

use App\Actions\Stock\MoveStockAction;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * TROIS APPELANTS DÉCLARAIENT LA RÈGLE, L'ÉCRIVAIN N'EN TENAIT AUCUNE.
 *
 * « On ne sort pas plus que ce qu'il y a » est énoncé TROIS fois autour de
 * `MoveStockAction`, et zéro fois dedans :
 *
 *   • `MoveStockRequest::withValidator()`        (le formulaire du magasin)
 *   • `EggMovementController::storeMovement()`   — dont le commentaire dit
 *      lui-même « Disponibilité, MIROIR de MoveStockRequest »
 *   • `SyncService::stockMovementCreate()`       (la file du terrain)
 *
 * L'Action, elle, fait `$stock->decrement('current_quantity', $quantity)` sans
 * borne : elle descend sous zéro sans un mot. Elle est pourtant le seul endroit
 * qui prenne le VERROU (`Stock::lockForUpdate()`), donc le seul qui puisse
 * trancher pour de bon.
 *
 * ─── CE QUE ÇA COÛTE, MESURÉ ───
 *
 * 1. UN QUATRIÈME APPELANT. L'Action est une porte publique et partagée — la
 *    spec mobile la range parmi les « Actions partagées ». Appelée avec une
 *    sortie de 500 sur un magasin de 100, elle laisse le stock à −400. Un stock
 *    négatif n'existe nulle part ailleurs dans l'ERP : `StockIntegrationService`
 *    borne à zéro et, en mode strict, REFUSE.
 *
 * 2. LA COURSE. Les trois vérifications lisent le stock HORS verrou ; le verrou
 *    n'est pris qu'ensuite, par l'Action, qui ne revérifie rien. Deux sorties
 *    concurrentes sur le même article passent donc toutes les deux la
 *    validation, et toutes les deux décrémentent.
 *
 * C'est exactement la leçon que `StockIntegrationService` a déjà tirée, en
 * toutes lettres : « la sortie est contrôlée SOUS le verrou pris ci-dessus.
 * Sans lui, le plafonnement à zéro faisait "disparaître" silencieusement la
 * matière manquante ». La règle y est tenue ; ici elle ne l'était pas.
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * On ne plafonne pas à zéro : ce serait faire disparaître la matière manquante,
 * précisément ce que ces commentaires condamnent. On refuse, comme le mode
 * strict du service voisin. Et on ne touche pas à l'AJUSTEMENT, qui pose une
 * valeur absolue d'inventaire et n'a rien à voir avec la disponibilité.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->magasin = Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Provende Ponte',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 100,
        'alert_threshold'  => 0,
    ]);
});

/** Le geste de sortie manuelle, par la porte partagée. */
function sortieManuelleDuMagasin(int $stockId, float $quantite, int $userId): void
{
    (new MoveStockAction())->execute($stockId, 'out', $quantite, 'Sortie manuelle', $userId);
}

test('sortir 500 d’un magasin qui en contient 100 est refusé', function () {
    /*
     * LE défaut : l'Action laissait le stock à −400, sans erreur ni trace.
     */
    expect(fn () => sortieManuelleDuMagasin($this->magasin->id, 500, $this->adminUser->id))
        ->toThrow(ValidationException::class);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(100.0);
});

test('la COURSE entre deux sorties ne creuse pas le stock', function () {
    /*
     * L'interleaving exact, joué pas à pas : les trois gardes existantes lisent
     * la disponibilité HORS verrou, et le verrou n'est pris qu'ensuite.
     *
     *   1. la sortie A lit « 100 disponibles » → sa validation passe ;
     *   2. la sortie B, plus rapide, consomme les 100 ;
     *   3. la sortie A s'exécute avec le chiffre qu'elle avait lu.
     *
     * Sans borne dans l'écrivain, l'étape 3 emmène le magasin à −80.
     */
    $disponibleLuParA = (float) $this->magasin->fresh()->current_quantity;   // 100

    expect($disponibleLuParA)->toBe(100.0);

    sortieManuelleDuMagasin($this->magasin->id, 100, $this->adminUser->id);  // B passe

    expect(fn () => sortieManuelleDuMagasin($this->magasin->id, 80, $this->adminUser->id))
        ->toThrow(ValidationException::class);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(0.0);
});

test('une sortie NORMALE passe toujours — non-régression', function () {
    // La borne : on refuse le découvert, pas la sortie.
    sortieManuelleDuMagasin($this->magasin->id, 40, $this->adminUser->id);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(60.0);
});

test('vider le magasin EXACTEMENT reste permis — non-régression', function () {
    // Le cas limite qu'un « > » mal placé casserait : sortir tout ce qu'il y a.
    sortieManuelleDuMagasin($this->magasin->id, 100, $this->adminUser->id);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(0.0);
});

test('l’AJUSTEMENT d’inventaire n’est pas concerné — non-régression', function () {
    /*
     * Un ajustement pose une valeur absolue constatée au comptage : il ne sort
     * rien, et la disponibilité n'a rien à lui dire. Le borner l'aurait rendu
     * incapable de corriger un stock à la baisse.
     */
    (new MoveStockAction())->execute($this->magasin->id, 'adjustment', 5, 'Inventaire physique', $this->adminUser->id);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(5.0);
});

test('l’ENTRÉE n’est pas concernée — non-régression', function () {
    (new MoveStockAction())->execute($this->magasin->id, 'in', 250, 'Réception', $this->adminUser->id);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(350.0);
});

test('le refus ne laisse AUCUN mouvement au registre', function () {
    /*
     * Un mouvement écrit pour une sortie refusée serait pire que le découvert :
     * le registre annoncerait une matière qui n'a pas bougé.
     */
    $avant = \App\Models\StockMovement::count();

    try {
        sortieManuelleDuMagasin($this->magasin->id, 500, $this->adminUser->id);
    } catch (ValidationException) {
        // attendu
    }

    expect(\App\Models\StockMovement::count())->toBe($avant);
});
