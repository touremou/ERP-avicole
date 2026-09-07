<?php

use App\Actions\DailyCheck\RecordDailyCheck;
use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * CORRIGER UNE CONSOMMATION D'ALIMENT À ZÉRO NE LA RESTITUAIT PAS.
 *
 * La compensation de stock d'un pointage corrigé restitue l'ANCIENNE
 * consommation avant de sortir la nouvelle. Mais elle était conditionnée par
 * `$feedConsumed > 0` — la NOUVELLE valeur.
 *
 * Corriger un pointage pour dire « en fait, aucun aliment n'a été donné »
 * — saisie sur le mauvais lot, distribution annulée — ne restituait donc RIEN.
 *
 * Mesuré, sur un magasin de 1 000 kg :
 *
 *   • 50 kg saisis            → 950 kg. Juste ;
 *   • puis corrigés à 0       → 950 kg. Il en faut 1 000.
 *
 * Cinquante kilos sortis du magasin, aucun mouvement pour les expliquer, et le
 * pointage affiche 0 : l'écart devient introuvable. Le lot, lui, perd le coût
 * de cet aliment dans sa marge, puisque sa consommation retombe à zéro.
 *
 * ─── LA BORNE QU'ON NE TESTE JAMAIS ───
 *
 * Corriger de 50 à 30 fonctionnait parfaitement. Le défaut ne frappait qu'au
 * ZÉRO — le seul cas où la nouvelle valeur ne peut pas servir de témoin à
 * l'existence de l'ancienne.
 *
 * ─── LA RÈGLE ───
 *
 * La restitution ne dépend que de ce qui avait été SORTI. C'est déjà ainsi que
 * le même bloc traite le fumier et l'eau, quelques lignes plus bas : ils passent
 * l'ancien ET le nouveau et laissent le delta se calculer, zéro compris. Trois
 * grandeurs corrigées au même endroit, une seule avec la mauvaise garde.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->lot = Batch::factory()->create([
        'farm_id'          => $this->farm->id,
        'building_id'      => $this->building->id,
        'initial_quantity' => 500,
        'current_quantity' => 500,
        'status'           => 'Actif',
    ]);

    $this->magasin = Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Provende Ponte',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 1000,
        'alert_threshold'  => 0,
    ]);
});

/** Saisit (ou corrige) le pointage du jour avec cette consommation d'aliment. */
function pointerConsoAliment(int $farmId, int $batchId, int $userId, float $kg, ?string $type = null): void
{
    app(RecordDailyCheck::class)->execute([
        'farm_id'       => $farmId,
        'batch_id'      => $batchId,
        'user_id'       => $userId,
        'check_date'    => today()->toDateString(),
        'feed_type'     => $type ?? 'Provende Ponte',
        'feed_consumed' => $kg,
    ]);
}

test('corriger la consommation À ZÉRO restitue l’aliment au magasin', function () {
    /*
     * LE défaut : les 50 kg sortaient et ne revenaient jamais.
     */
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 50);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(950.0);

    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 0);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(1000.0);
});

test('le pointage corrigé ne porte plus aucune consommation', function () {
    // La contrepartie côté lot : sans elle, le magasin serait juste mais la
    // marge du lot continuerait de porter un aliment qu'il n'a pas reçu.
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 50);
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 0);

    $pointage = DailyCheck::where('batch_id', $this->lot->id)->firstOrFail();

    expect((float) $pointage->feed_consumed)->toBe(0.0);
});

test('corriger À LA BAISSE reste juste — non-régression', function () {
    /*
     * LA borne qui rend le défaut invisible : ce cas-là fonctionnait, et c'est
     * celui qu'on essaie spontanément.
     */
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 50);
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 30);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(970.0);
});

test('corriger À LA HAUSSE reste juste — non-régression', function () {
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 30);
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 80);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(920.0);
});

test('changer le TYPE d’aliment rend à l’article d’où c’est sorti', function () {
    /*
     * La restitution vise `$existing->feed_type`, pas le nouveau. Se tromper
     * d'article ferait apparaître du stock dans l'un et disparaître dans
     * l'autre — deux erreurs pour une correction.
     */
    $demarrage = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Provende Démarrage',
        'category' => Stock::CAT_CONSO, 'unit' => 'KG',
        'current_quantity' => 500, 'alert_threshold' => 0,
    ]);

    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 50);                              // Ponte : 1000 → 950
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 50, 'Provende Démarrage');        // rendu à Ponte, sorti de Démarrage

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(1000.0)
        ->and((float) $demarrage->fresh()->current_quantity)->toBe(450.0);
});

test('un pointage SANS aliment dès l’origine n’écrit AUCUN mouvement', function () {
    /*
     * Le garde ne doit se poser que s'il y a eu une sortie à annuler.
     *
     * Et l'assertion porte sur le REGISTRE, pas sur le solde : une restitution
     * de zéro laisse le solde juste, mais `syncMovement` écrit quand même sa
     * ligne. Un registre de stock qui se remplit de mouvements à zéro à chaque
     * correction est un registre dans lequel on ne retrouve plus les vrais.
     */
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 0);
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 0);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(1000.0)
        ->and(StockMovement::where('stock_id', $this->magasin->id)->count())->toBe(0);
});

test('une PREMIÈRE saisie déstocke normalement — non-régression', function () {
    pointerConsoAliment($this->farm->id, $this->lot->id, $this->adminUser->id, 50);

    expect((float) $this->magasin->fresh()->current_quantity)->toBe(950.0);
});
