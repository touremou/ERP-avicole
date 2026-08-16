<?php

use App\Models\Stock;
use App\Models\StockAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'EXPORT DU JOURNAL DE DÉMARQUE JETAIT LES FILTRES DE L'ÉCRAN.
 *
 * La même requête était écrite TROIS fois dans le contrôleur : filtrée dans
 * `index()` — par article, par motif, par type — et NON filtrée dans les deux
 * exports, qui ne retenaient que la période.
 *
 * Ce n'était pas un oubli sans portée. La vue construit ses liens d'export avec
 * `request()->query()` : les filtres SONT transmis (`?stock_id=3&type=perte`),
 * et les exports les jetaient. L'utilisateur ne se trompe pas de bouton — c'est
 * le serveur qui ignore ce qu'il lui envoie.
 *
 * ─── MESURÉ ───
 *
 * Journal filtré sur l'article « Aliment démarrage » et sur les pertes :
 *
 *     à l'écran ....... 1 ligne
 *     dans le CSV ..... 3 lignes, dont un GAIN explicitement écarté
 *                       et un ARTICLE qui n'a rien à voir
 *
 * ─── POURQUOI CELA COMPTE ───
 *
 * Ce document sort sous le nom « démarque ». C'est la pièce qui justifie la
 * casse et les écarts d'inventaire — celle qu'on transmet. Celui qui la reçoit
 * croit lire la sélection qu'on lui a annoncée ; il lit autre chose, sans qu'un
 * mot le signale.
 *
 * ─── LE RESTE DES EXPORTS EST SAIN ───
 *
 * Vérifié : les rapports cultures (rendement, intrants, campagnes,
 * transformations), le compte de résultat, le GMQ, la trésorerie, la présence,
 * les budgets, le relevé fournisseur et le journal des retours appellent TOUS le
 * même constructeur que leur écran. `stock-adjustments` était le seul écart.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->article = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Aliment démarrage',
        'category' => Stock::CAT_CONSO, 'current_quantity' => 500, 'unit' => 'kg',
        'unit_price' => 5_000, 'alert_threshold' => 100,
    ]);

    $this->autre = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Maïs concassé',
        'category' => Stock::CAT_CONSO, 'current_quantity' => 800, 'unit' => 'kg',
        'unit_price' => 4_000, 'alert_threshold' => 100,
    ]);
});

/** Un ajustement daté d'aujourd'hui sur l'article donné. */
function ajustement(int $farmId, int $stockId, int $userId, string $type, string $motif = 'casse'): StockAdjustment
{
    return StockAdjustment::create([
        'farm_id' => $farmId, 'stock_id' => $stockId, 'user_id' => $userId,
        'reference' => 'AJU-' . random_int(1000, 9999),
        'type' => $type, 'reason' => $motif,
        'quantity_before' => 100, 'quantity_after' => 90, 'delta' => -10,
        'unit_cost' => 5_000, 'value_impact' => 50_000,
        'adjustment_date' => now()->toDateString(),
    ]);
}

/** Nombre de lignes de données du CSV (en-tête exclu). */
function lignesDuCsv(string $contenu): int
{
    return max(0, substr_count(trim($contenu), "\n"));
}

test('le CSV filtré ne contient que ce que l’écran montre', function () {
    /*
     * LE défaut, chiffré : 1 ligne affichée, 3 exportées.
     */
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte');
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'gain');
    ajustement($this->farm->id, $this->autre->id, $this->adminUser->id, 'perte');

    $filtres = ['stock_id' => $this->article->id, 'type' => 'perte'];

    $ecran = $this->get(route('stock-adjustments.index', $filtres))->assertOk();
    $csv   = $this->get(route('stock-adjustments.csv', $filtres))->assertOk();

    expect(lignesDuCsv($csv->streamedContent()))
        ->toBe($ecran->viewData('adjustments')->total());
});

test('le CSV n’emporte ni l’autre article ni le type écarté', function () {
    // Le même défaut, dit par le contenu plutôt que par le compte.
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte');
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'gain');
    ajustement($this->farm->id, $this->autre->id, $this->adminUser->id, 'perte');

    $contenu = $this->get(route('stock-adjustments.csv', [
        'stock_id' => $this->article->id, 'type' => 'perte',
    ]))->assertOk()->streamedContent();

    expect($contenu)->not->toContain('Maïs concassé')
        ->and($contenu)->toContain('Aliment démarrage')
        ->and(substr_count($contenu, 'gain'))->toBe(0);
});

test('le filtre par MOTIF est respecté aussi', function () {
    // Les trois filtres de l'écran comptent, pas seulement les deux premiers.
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte', 'casse');
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte', 'vol');

    $csv = $this->get(route('stock-adjustments.csv', ['reason' => 'vol']))->assertOk();

    expect(lignesDuCsv($csv->streamedContent()))->toBe(1);
});

test('le PDF suit la même règle que le CSV', function () {
    /*
     * Les deux exports partageaient le défaut ; ils doivent partager le
     * correctif. Le PDF est un flux binaire : on vérifie qu'il se rend, et on
     * mesure le périmètre par le CSV, qui lit la même requête.
     */
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte');
    ajustement($this->farm->id, $this->autre->id, $this->adminUser->id, 'perte');

    $this->get(route('stock-adjustments.pdf', ['stock_id' => $this->article->id]))->assertOk();

    $csv = $this->get(route('stock-adjustments.csv', ['stock_id' => $this->article->id]))->assertOk();

    expect(lignesDuCsv($csv->streamedContent()))->toBe(1);
});

test('SANS filtre, l’export contient bien tout', function () {
    /*
     * La borne : on aligne l'export sur l'écran, on ne le rétrécit pas. Sans
     * cette mesure, un filtrage trop zélé ferait passer les tests ci-dessus.
     */
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte');
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'gain');
    ajustement($this->farm->id, $this->autre->id, $this->adminUser->id, 'perte');

    $csv = $this->get(route('stock-adjustments.csv'))->assertOk();

    expect(lignesDuCsv($csv->streamedContent()))->toBe(3);
});

test('les indicateurs de l’écran comparent toujours pertes ET gains', function () {
    /*
     * La nuance à ne pas emporter : les indicateurs de tête excluent le filtre
     * de TYPE, à dessein — ils opposent justement les pertes aux gains. Les
     * aligner sur la liste les aurait vidés dès qu'on filtre sur un type.
     */
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'perte');
    ajustement($this->farm->id, $this->article->id, $this->adminUser->id, 'gain');

    $stats = $this->get(route('stock-adjustments.index', ['type' => 'perte']))
        ->assertOk()->viewData('stats');

    expect($stats['loss_value'])->toBeGreaterThan(0.0)
        ->and($stats['gain_value'])->toBeGreaterThan(0.0);
});
