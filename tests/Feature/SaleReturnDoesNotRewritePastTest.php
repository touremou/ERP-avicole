<?php

use App\Actions\Sale\ProcessSaleReturn;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Services\Accounting\PeriodRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN RETOUR DE SEPTEMBRE RÉÉCRIVAIT LE CHIFFRE D'AFFAIRES DE JUILLET.
 *
 * `ProcessSaleReturn` DÉCRÉMENTE la ligne de vente d'origine — et la SUPPRIME si
 * le retour est total. Les rapports, eux, sélectionnent les ventes par leur DATE
 * DE VENTE. Un retour postérieur modifiait donc rétroactivement une période
 * arrêtée.
 *
 * Mesuré : une vente de 5 000 000 GNF datée du 15 juillet, dont le client rend
 * la moitié le 16 août. Le compte de résultat de JUILLET tombait de 5 000 000 à
 * 2 500 000 — un mois clos, peut-être déjà imprimé et transmis au promoteur, qui
 * est à l'étranger et n'a que ce document.
 *
 * C'est le principe que cette base défend partout ailleurs — « supprimer une
 * source d'énergie ne doit pas RÉÉCRIRE le passé » — appliqué à la plus grosse
 * ligne de produits.
 *
 * ─── CE QU'ON NE CHANGE PAS ───
 *
 * Le geste de retour. La marchandise est revenue : stock, solde client,
 * remboursement et statut de paiement doivent bouger, et ils le faisaient déjà
 * correctement (vérifié : plafond sur le RESTANT, remise en stock sur l'article
 * d'origine, paiement négatif, trésorerie débitée du bon sens). Ce qui était
 * faux, c'est la PÉRIODE à laquelle le rapport imputait la baisse.
 *
 * ─── LA RÈGLE ───
 *
 *     CA(P) = Σ lignes de vente (ventes datées dans P)
 *           + Σ retours (vente dans P, retour APRÈS P)
 *
 * Un retour survenu DANS la période reste déduit : vente et retour tombent tous
 * deux dans P, le net est juste.
 *
 * ─── DEUX RAPPORTS, UNE SEULE DÉCLARATION ───
 *
 * Le compte de résultat ventile par CATÉGORIE, la rentabilité par espèce passe
 * par le LOT. Les deux souffraient du même défaut. Corriger l'un et pas l'autre
 * aurait reproduit exactement ce que cet audit corrige depuis le début : la même
 * règle appliquée à un endroit sur deux.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-001',
        'name' => 'Boulangerie Centrale', 'category' => 'detaillant',
        'phone' => '620000000',
    ]);

    $this->debut = now()->subMonth()->startOfMonth();
    $this->fin   = now()->subMonth()->endOfMonth();
});

/** Vente de 5 000 000 GNF datée du 15 du mois dernier. */
function venteDuMoisDernier(int $farmId, int $clientId, int $userId): Sale
{
    $vente = Sale::create([
        'farm_id' => $farmId, 'client_id' => $clientId,
        'reference' => 'VTE-' . random_int(1000, 9999),
        'sale_date' => now()->subMonth()->startOfMonth()->addDays(14)->toDateString(),
        'status' => 'valide',
        'total_amount' => 5_000_000, 'paid_amount' => 0,
        'user_id' => $userId,
    ]);

    SaleItem::create([
        'sale_id' => $vente->id,
        'product_type' => 'oeufs', 'product_name' => 'Œufs calibre L',
        'quantity' => 100, 'unit' => 'Alvéole',
        'unit_price' => 50_000, 'total' => 5_000_000,
    ]);

    return $vente->fresh();
}

/** Le chiffre d'affaires du mois dernier, tel que le rapport l'affiche. */
function caDuMoisDernier(): float
{
    return (float) test()->get(route('reports.profit_loss', [
        'date_from' => now()->subMonth()->startOfMonth()->toDateString(),
        'date_to'   => now()->subMonth()->endOfMonth()->toDateString(),
    ]))->assertOk()->viewData('totalRevenue');
}

test('un retour du mois SUIVANT ne touche pas le mois arrêté', function () {
    /*
     * LE défaut, chiffré : 5 000 000 → 2 500 000 sur un mois déjà clos.
     */
    $vente = venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);

    expect(caDuMoisDernier())->toBe(5000000.0);

    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 50], 'Casse');

    expect(caDuMoisDernier())->toBe(5000000.0);
});

test('un retour TOTAL non plus, ligne de vente supprimée comprise', function () {
    /*
     * Le cas dur : `sale_items` n'a pas de suppression douce, la ligne
     * disparaît. Sans l'instantané de catégorie, le chiffre serait irrécupérable.
     */
    $vente = venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);

    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 100], 'Lot refusé');

    expect($vente->fresh()->items()->count())->toBe(0)
        ->and(caDuMoisDernier())->toBe(5000000.0);
});

test('la CATÉGORIE du produit retourné est conservée', function () {
    // Sans elle, le total serait juste mais la ventilation muette — et le
    // rapport rangerait le chiffre au hasard.
    $vente = venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);

    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 100], 'Lot refusé');

    expect(SaleReturnItem::first()->product_type)->toBe('oeufs');

    $ventilation = PeriodRevenue::byProductType($this->debut, $this->fin);

    expect($ventilation)->toHaveKey('oeufs')
        ->and($ventilation['oeufs'])->toBe(5000000.0)
        ->and($ventilation)->not->toHaveKey(PeriodRevenue::LIBELLE_NON_VENTILE);
});

test('un retour DANS la période reste déduit', function () {
    /*
     * La borne qui empêche de tout réintégrer aveuglément. Vente ET retour dans
     * le même mois : le net est juste, il ne faut rien rajouter.
     */
    $vente = venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);

    // Retour daté du 20 du mois dernier — dans la période.
    $retour = app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 50], 'Casse');
    $retour->update(['return_date' => now()->subMonth()->startOfMonth()->addDays(19)->toDateString()]);

    expect(caDuMoisDernier())->toBe(2500000.0);
});

test('le mois du RETOUR enregistre bien la baisse', function () {
    /*
     * L'autre moitié de la règle : on ne fait pas disparaître le retour, on le
     * met dans SA période. Le mois courant porte la vente résiduelle.
     */
    $vente = venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);

    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 50], 'Casse');

    // Fenêtre couvrant la vente ET le retour : le net doit apparaître.
    $global = (float) $this->get(route('reports.profit_loss', [
        'date_from' => now()->subMonth()->startOfMonth()->toDateString(),
        'date_to'   => now()->endOfMonth()->toDateString(),
    ]))->assertOk()->viewData('totalRevenue');

    expect($global)->toBe(2500000.0);
});

test('la rentabilité par espèce est protégée de la même façon', function () {
    /*
     * Le second rapport. Il ventile par LOT — d'où l'instantané `batch_id` en
     * plus de la catégorie.
     */
    $lot = \App\Models\Batch::factory()->create(['farm_id' => $this->farm->id]);

    $vente = venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);
    $vente->items()->first()->update(['batch_id' => $lot->id]);

    $avant = PeriodRevenue::forBatches([$lot->id], $this->debut, $this->fin);

    app(ProcessSaleReturn::class)->execute($vente, [$vente->items()->first()->id => 100], 'Lot refusé');

    expect(PeriodRevenue::forBatches([$lot->id], $this->debut, $this->fin))->toBe($avant)
        ->and($avant)->toBe(5000000.0);
});

test('une vente SANS retour donne exactement le même chiffre qu’avant', function () {
    // La borne la plus importante : la reconstitution ne doit rien changer au
    // cas ordinaire, qui est l'immense majorité.
    venteDuMoisDernier($this->farm->id, $this->client->id, $this->adminUser->id);

    expect(caDuMoisDernier())->toBe(5000000.0)
        ->and(PeriodRevenue::byProductType($this->debut, $this->fin))->toBe(['oeufs' => 5000000.0]);
});
