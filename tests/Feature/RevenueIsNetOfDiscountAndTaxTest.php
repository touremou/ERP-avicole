<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Accounting\PeriodRevenue;
use App\Services\DashboardInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE CHIFFRE D'AFFAIRES COMPTAIT LA TVA D'UN CÔTÉ, IGNORAIT LA REMISE DE L'AUTRE.
 *
 * Deux écrans répondaient à « combien ai-je vendu ce mois-ci » avec deux nombres,
 * et aucun des deux n'était juste :
 *
 *   • le COMPTE DE RÉSULTAT sommait `sale_items.total` — le brut AVANT remise.
 *     Une remise accordée ne réduisait donc pas la recette enregistrée : le
 *     rapport annonçait plus que ce que le client avait été facturé ;
 *   • le TABLEAU DE BORD sommait `sales.total_amount` — le TTC, donc TVA
 *     COMPRISE. Il comptait dans le chiffre d'affaires de la ferme un argent qui
 *     appartient à l'État.
 *
 * Mesuré sur une seule facture d'un million de marchandise, remise 10 %, TVA
 * 18 %, livraison 50 000 : compte de résultat 1 000 000, tableau de bord
 * 1 112 000. Cent douze mille francs d'écart, qui croît avec chaque remise,
 * chaque taxe et chaque livraison.
 *
 * ─── LA RÈGLE, CELLE DE TOUS LES RÉFÉRENTIELS ───
 *
 * Le chiffre d'affaires est NET DES REMISES et EXCLUT LA TAXE COLLECTÉE. Les
 * frais de livraison facturés sont une recette, mais d'une autre nature : ils ont
 * leur propre ligne plutôt que de gonfler la vente de marchandise.
 *
 * La remise est portée par la VENTE, pas par la ligne : elle est répartie au
 * prorata du poids de chaque type de produit dans le sous-total. Sans cela, une
 * remise sur une facture mixte s'imputerait arbitrairement.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-' . Str::random(6),
        'name' => 'Grossiste', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif',
    ]);
});

/** Une vente validée, avec ses lignes, sa remise, sa taxe et sa livraison. */
function venteFacturee(int $farmId, int $clientId, int $userId, array $lignes, array $options = []): Sale
{
    $vente = Sale::create(array_merge([
        'farm_id' => $farmId, 'uuid' => (string) Str::uuid(),
        'reference' => 'FA-' . Str::random(6), 'client_id' => $clientId,
        'sale_date' => today()->toDateString(), 'type' => 'facture_tva',
        'status' => 'valide', 'user_id' => $userId,
    ], $options));

    foreach ($lignes as $type => $montant) {
        SaleItem::create([
            'sale_id' => $vente->id, 'product_type' => $type,
            'product_name' => ucfirst($type), 'quantity' => 1,
            'unit_price' => $montant, 'total' => $montant,
        ]);
    }

    $vente->recalculateTotals();

    return $vente->fresh();
}

test('la TVA n’est PAS du chiffre d’affaires', function () {
    /*
     * Le plus grave des deux : la taxe collectée appartient à l'État. La compter
     * en recette gonfle le résultat de 18 % sur toute facture taxée.
     */
    venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id,
        ['oeufs' => 1_000_000], ['tax_rate' => 18]);

    $recettes = array_sum(PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay()));

    expect($recettes)->toBe(1_000_000.0);
});

test('une REMISE accordée réduit la recette', function () {
    /*
     * L'autre bord. Le compte de résultat annonçait le brut : plus que ce que le
     * client a été facturé.
     */
    venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id,
        ['oeufs' => 1_000_000], ['discount_type' => 'percent', 'discount_value' => 10]);

    $recettes = array_sum(PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay()));

    expect($recettes)->toBe(900_000.0);
});

test('la remise se répartit AU PRORATA sur une facture mixte', function () {
    /*
     * La remise est portée par la vente. L'imputer arbitrairement à une seule
     * catégorie fausserait la rentabilité de cette catégorie.
     */
    venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id,
        ['oeufs' => 750_000, 'aliment' => 250_000],
        ['discount_type' => 'percent', 'discount_value' => 20]);

    $parType = PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay());

    // 20 % de remise : chaque catégorie perd 20 % des siens, pas plus.
    expect($parType['oeufs'])->toBe(600_000.0)
        ->and($parType['aliment'])->toBe(200_000.0);
});

test('la LIVRAISON facturée est une recette, sur sa propre ligne', function () {
    /*
     * Elle ne doit ni disparaître (c'est de l'argent encaissé) ni se fondre dans
     * la marchandise (elle fausserait la rentabilité du produit).
     */
    venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id,
        ['oeufs' => 1_000_000], ['delivery_fee' => 50_000]);

    $parType = PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay());

    expect($parType['oeufs'])->toBe(1_000_000.0)
        ->and($parType[PeriodRevenue::LIBELLE_LIVRAISON])->toBe(50_000.0);
});

test('le TABLEAU DE BORD et le COMPTE DE RÉSULTAT disent le même nombre', function () {
    /*
     * LA borne de cette correction, sur le cas complet qui les faisait diverger
     * de 112 000 : remise, taxe et livraison réunies.
     */
    venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id,
        ['oeufs' => 1_000_000],
        ['tax_rate' => 18, 'discount_type' => 'percent', 'discount_value' => 10, 'delivery_fee' => 50_000]);

    $compteDeResultat = array_sum(PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay()));
    $tableauDeBord    = app(DashboardInsightsService::class)
        ->financial(now()->startOfMonth(), now()->endOfMonth())['ca_ventes'];

    // 1 000 000 − 10 % de remise + 50 000 de livraison. La TVA reste dehors.
    expect($compteDeResultat)->toBe(950_000.0)
        ->and((float) $tableauDeBord)->toBe($compteDeResultat);
});

test('une vente ORDINAIRE ne bouge pas d’un franc', function () {
    /*
     * La borne de non-régression : sans remise, sans taxe et sans livraison, le
     * ratio vaut 1 et le chiffre est exactement celui d'avant.
     */
    venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id, ['oeufs' => 640_000]);

    expect(array_sum(PeriodRevenue::byProductType(now()->startOfYear(), now()->endOfDay())))
        ->toBe(640_000.0);
});

test('la RENTABILITÉ PAR ESPÈCE applique la même règle', function () {
    /*
     * Troisième lecteur de la même question. Sans le prorata, la marge d'une
     * espèce se calculait sur un chiffre d'affaires jamais encaissé.
     */
    $lot = \App\Models\Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'status' => 'Actif',
    ]);

    $vente = venteFacturee($this->farm->id, $this->client->id, $this->adminUser->id,
        ['animal_vif' => 1_000_000], ['discount_type' => 'percent', 'discount_value' => 25]);

    SaleItem::where('sale_id', $vente->id)->update(['batch_id' => $lot->id]);

    expect(PeriodRevenue::forBatches([$lot->id], now()->startOfYear(), now()->endOfDay()))
        ->toBe(750_000.0);
});
