<?php

use App\Actions\Sale\CancelSale;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\ProcessSaleReturn;
use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE CONTRÔLE DE DISPONIBILITÉ ET LA SORTIE DE STOCK NE PARLAIENT PAS LA MÊME
 * LANGUE.
 *
 * `ValidateSale::destockItem()` comparait `$item->quantity` — la quantité dans
 * l'unité de SAISIE (sac, pièce, alvéole…) — à `$stock->current_quantity`, qui
 * est dans l'unité PIVOT de la catégorie : KG pour l'aliment, Alvéole pour les
 * œufs. Deux nombres dans deux unités, comparés directement.
 *
 * Trois lignes plus bas, la sortie réelle, elle, CONVERTIT (`syncMovement` reçoit
 * l'unité de saisie et normalise). Le garde et le geste divergeaient donc par
 * construction.
 *
 * Mesuré, dans les deux sens :
 *
 *   • 5 sacs d'aliment (250 kg) vendus sur 100 kg en stock : « 5 < 100 », donc
 *     contrôle PASSÉ. 250 kg sortis, stock plafonné à zéro. Une vente de 150 kg
 *     qui n'ont jamais existé, validée sans un mot ;
 *   • 300 œufs vendus à la pièce sur 20 alvéoles — soit 600 œufs, le double de
 *     ce qu'il faut : « 300 > 20 », vente REFUSÉE.
 *
 * ─── LE SECOND DÉFAUT, EN DESSOUS ───
 *
 * `SaleItem::stockInputUnit()` faisait tomber la pièce (`unite`, `piece`) dans
 * son `default => 'KG'`. Or c'est précisément l'unité que
 * `UnitConverter::isEggPiece()` reconnaît pour diviser par le nombre d'œufs par
 * alvéole. La table de conversion connaissait la pièce ; ce `match` la jetait.
 *
 * Sur des œufs stockés en alvéoles, vendre 300 œufs à la pièce annonçait donc
 * « 300 KG » — aucune conversion — et sortait 300 ALVÉOLES, soit 9 000 œufs.
 * C'est ce second défaut qui rendait le premier peu visible : la vente était
 * refusée avant d'avoir pu vider le magasin.
 *
 * ─── LA RÈGLE RETENUE ───
 *
 * La conversion se fait UNE fois, et le même nombre sert au contrôle et au
 * mouvement. `strictOut: true` ferme la porte derrière : une sortie plus grande
 * que le stock ne se plafonne plus en silence — c'est ce plafonnement qui avait
 * absorbé les 150 kg manquants.
 *
 * L'annulation et le retour partagent déjà `stockInputUnit()` : ils remettent en
 * stock exactement ce que la validation en avait sorti, et le correctif de la
 * pièce vaut pour les trois d'un coup.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-UNIT',
        'name' => 'Grossiste', 'type' => 'entreprise',
        'category' => 'grossiste', 'status' => 'actif',
    ]);
});

/** Un article de stock dans son unité pivot. */
function articleDeStockPourUnites(int $farmId, string $nom, string $categorie, string $unite, float $qte): Stock
{
    return Stock::create([
        'farm_id' => $farmId, 'item_name' => $nom, 'category' => $categorie,
        'unit' => $unite, 'current_quantity' => $qte, 'alert_threshold' => 0,
    ]);
}

/** Crée une vente d'une ligne sur cet article, dans l'unité de saisie voulue. */
function venteUneLigneSurUnArticle(int $clientId, Stock $stock, string $type, float $qte, string $unite): Sale
{
    return (new CreateSale())->execute([
        'client_id' => $clientId,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => $type,
            'product_name' => $stock->item_name,
            'product_id'   => $stock->id,
            'quantity'     => $qte,
            'unit'         => $unite,
            'unit_price'   => 1_000,
        ]],
    ]);
}

function validerLaVenteEnUnites(Sale $vente): void
{
    (new ValidateSale())->execute($vente->fresh(['items', 'client']));
}

test('vendre à la PIÈCE des œufs stockés en alvéoles sort le bon nombre', function () {
    /*
     * LE défaut composé : 20 alvéoles = 600 œufs. On en vend 300, ce qui doit
     * en laisser 300, soit 10 alvéoles. Avant, la vente était refusée — et si
     * elle était passée, elle aurait sorti 300 alvéoles (9 000 œufs).
     */
    $stock = articleDeStockPourUnites($this->farm->id, 'Œufs calibre M', Stock::CAT_OEUFS, 'Alvéole', 20);

    validerLaVenteEnUnites(venteUneLigneSurUnArticle($this->client->id, $stock, 'oeufs', 300, 'unite'));

    expect((float) $stock->fresh()->current_quantity)->toBe(10.0);
});

test('vendre plus de SACS que le magasin n’en contient est refusé', function () {
    /*
     * L'autre sens, et le plus coûteux : 5 sacs = 250 kg, il n'y en a que 100.
     * Le contrôle passait (« 5 < 100 »), la sortie plafonnait à zéro, et
     * l'exploitation livrait 150 kg d'aliment qu'elle n'avait pas.
     */
    $stock = articleDeStockPourUnites($this->farm->id, 'Provende Ponte', Stock::CAT_CONSO, 'KG', 100);

    $vente = venteUneLigneSurUnArticle($this->client->id, $stock, 'aliment', 5, 'sac');

    expect(fn () => validerLaVenteEnUnites($vente))->toThrow(Exception::class, 'Stock insuffisant');
    expect((float) $stock->fresh()->current_quantity)->toBe(100.0);
});

test('vendre EXACTEMENT le stock disponible reste permis', function () {
    /*
     * LA borne du contrôle : 2 sacs = 100 kg, il y en a 100. Une comparaison
     * trop stricte aurait interdit de vider un magasin — ce qui est le cas le
     * plus banal en fin de lot.
     */
    $stock = articleDeStockPourUnites($this->farm->id, 'Provende Ponte', Stock::CAT_CONSO, 'KG', 100);

    validerLaVenteEnUnites(venteUneLigneSurUnArticle($this->client->id, $stock, 'aliment', 2, 'sac'));

    expect((float) $stock->fresh()->current_quantity)->toBe(0.0);
});

test('vendre dans l’unité PIVOT elle-même ne bouge pas — non-régression', function () {
    // Le cas courant : le sélecteur de stock propose l'unité de l'article.
    $stock = articleDeStockPourUnites($this->farm->id, 'Œufs calibre M', Stock::CAT_OEUFS, 'Alvéole', 20);

    validerLaVenteEnUnites(venteUneLigneSurUnArticle($this->client->id, $stock, 'oeufs', 8, 'alveole'));

    expect((float) $stock->fresh()->current_quantity)->toBe(12.0);
});

test('l’ANNULATION remet exactement ce que la vente avait sorti', function () {
    /*
     * La symétrie, sur l'unité qui divergeait. Sortir 10 alvéoles et en
     * remettre 300 aurait fabriqué du stock à chaque aller-retour.
     */
    $stock = articleDeStockPourUnites($this->farm->id, 'Œufs calibre M', Stock::CAT_OEUFS, 'Alvéole', 20);

    $vente = venteUneLigneSurUnArticle($this->client->id, $stock, 'oeufs', 300, 'unite');
    validerLaVenteEnUnites($vente);

    expect((float) $stock->fresh()->current_quantity)->toBe(10.0);

    (new CancelSale())->execute($vente->fresh(['items', 'client']), 'Erreur de saisie');

    expect((float) $stock->fresh()->current_quantity)->toBe(20.0);
});

test('un RETOUR partiel à la pièce remet le bon nombre d’alvéoles', function () {
    // 300 œufs vendus, 150 rendus → 5 alvéoles reviennent, pas 150.
    $stock = articleDeStockPourUnites($this->farm->id, 'Œufs calibre M', Stock::CAT_OEUFS, 'Alvéole', 20);

    $vente = venteUneLigneSurUnArticle($this->client->id, $stock, 'oeufs', 300, 'unite');
    validerLaVenteEnUnites($vente);

    app(ProcessSaleReturn::class)->execute(
        $vente->fresh(['items', 'client']),
        [$vente->items->first()->id => 150],
        'Œufs cassés à la livraison',
    );

    expect((float) $stock->fresh()->current_quantity)->toBe(15.0);
});

test('une ligne SANS article de stock se vend toujours — non-régression', function () {
    /*
     * La borne de portée : `strictOut` ne doit pas transformer en erreur une
     * vente que l'ERP autorise depuis toujours — un article libre, non suivi en
     * stock (fumier, prestation).
     */
    $vente = (new CreateSale())->execute([
        'client_id' => $this->client->id,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'fumier', 'product_name' => 'Fumier en vrac',
            'quantity' => 3, 'unit' => 'voyage', 'unit_price' => 150_000,
        ]],
    ]);

    validerLaVenteEnUnites($vente);

    expect($vente->fresh()->status)->toBe('valide');
});
