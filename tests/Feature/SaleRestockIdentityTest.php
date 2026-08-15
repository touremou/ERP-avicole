<?php

use App\Actions\Sale\CancelSale;
use App\Actions\Sale\ProcessSaleReturn;
use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MARCHANDISE NE REVENAIT PAS DANS L'ARTICLE D'OÙ ELLE ÉTAIT SORTIE.
 *
 * Trois actions touchent le stock d'une vente, et elles ne désignaient pas
 * l'article de la même façon :
 *
 *   • la VALIDATION résolvait par `product_id`, puis par nom, et écrivait sur
 *     l'identité RÉELLE de l'article trouvé — avec, sur place, un commentaire
 *     disant qu'il ne fallait SURTOUT PAS dériver la catégorie du type produit,
 *     « quelle que soit sa catégorie (œufs, lait, aliment… mais aussi litière,
 *     matériel, etc.) » ;
 *
 *   • l'ANNULATION et le RETOUR client ignoraient `product_id` et
 *     reconstruisaient la catégorie depuis `product_type` — exactement ce que
 *     ce commentaire proscrit.
 *
 * Or `requiresDestock()` est vrai dès qu'un `product_id` existe, TOUTE catégorie
 * confondue. Une ligne dont la catégorie réelle ne se déduit pas du type sortait
 * du bon article et revenait dans un autre — ou dans aucun, car `syncMovement`
 * renvoie false sur article introuvable SANS lever : la marchandise revenait
 * physiquement, la vente était annulée, et le stock ne la retrouvait jamais.
 * Aucune erreur, aucune alerte : un écart d'inventaire qui naît tout seul.
 *
 * La résolution vit désormais sur SaleItem (resolveStock) et les trois actions
 * l'appellent. La table de conversion d'unités, recopiée trois fois, y a
 * rejoint la même méthode (stockInputUnit).
 *
 * CE QU'ON N'A PAS FAIT : bloquer. Une annulation ou un retour restent
 * légitimes même si aucun article ne correspond — la marchandise est déjà
 * revenue et l'avoir est dû. L'échec cesse simplement d'être MUET.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-RSTK',
        'name' => 'Boutique Kindia', 'type' => 'entreprise', 'category' => 'detaillant',
        'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);
});

/**
 * Article dont la CATÉGORIE ne se déduit pas du product_type de la ligne :
 * le cas exact où les deux résolutions divergeaient.
 */
function articleHorsMapping(): Stock
{
    return Stock::create([
        'category' => Stock::CAT_LITIERES, 'item_name' => 'Copeaux de bois',
        'unit' => 'kg', 'current_quantity' => 500,
        'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);
}

/** Vente d'une ligne adossée à $stock, mais typée « materiel ». */
function venteSur(Stock $stock, int $clientId, float $qty = 100): Sale
{
    $vente = Sale::create([
        'client_id' => $clientId, 'user_id' => auth()->id(),
        'reference' => \App\Services\SaleNumberingService::generate('bon_livraison'),
        'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'status' => 'brouillon',
    ]);

    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'materiel',
        'product_name' => $stock->item_name, 'product_id' => $stock->id,
        'quantity' => $qty, 'unit' => 'kg', 'unit_price' => 1000, 'total' => $qty * 1000,
    ]);

    $vente->recalculateTotals();

    return $vente->fresh();
}

test('ANNULATION : la marchandise revient dans l’article d’où elle est sortie', function () {
    // LE défaut. La sortie visait « Copeaux de bois » en litières ; le retour
    // cherchait le même nom en matériels — donc rien, en silence.
    $article = articleHorsMapping();
    $vente = venteSur($article, $this->client->id);

    app(ValidateSale::class)->execute($vente);
    expect((float) $article->fresh()->current_quantity)->toBe(400.0);

    app(CancelSale::class)->execute($vente->fresh(), 'Client absent');

    expect((float) $article->fresh()->current_quantity)->toBe(500.0);
});

test('ANNULATION : aucun article fantôme n’est créé au passage', function () {
    // L'autre visage du même défaut : écrire dans la mauvaise catégorie.
    $article = articleHorsMapping();
    $vente = venteSur($article, $this->client->id);

    app(ValidateSale::class)->execute($vente);
    app(CancelSale::class)->execute($vente->fresh(), 'Client absent');

    expect(Stock::where('item_name', 'Copeaux de bois')->count())->toBe(1);
});

test('RETOUR CLIENT : la reprise revient dans le même article', function () {
    $article = articleHorsMapping();
    $vente = venteSur($article, $this->client->id);

    app(ValidateSale::class)->execute($vente);
    $ligne = $vente->fresh()->items->first();

    app(ProcessSaleReturn::class)->execute($vente->fresh(), [$ligne->id => 40], 'Excédent');

    expect((float) $article->fresh()->current_quantity)->toBe(440.0);
});

test('la sortie et le retour visent le MÊME article, quel que soit le type', function () {
    // La formulation directe de la règle : quel que soit le product_type de la
    // ligne, c'est l'article lié qui fait foi, à l'aller comme au retour.
    $article = articleHorsMapping();
    $vente = venteSur($article, $this->client->id, 250);

    $avant = (float) $article->current_quantity;

    app(ValidateSale::class)->execute($vente);
    app(CancelSale::class)->execute($vente->fresh(), 'Test de symétrie');

    expect((float) $article->fresh()->current_quantity)->toBe($avant);
});

test('une vente ORDINAIRE reste inchangée (non-régression)', function () {
    // Le cas courant — un calibre d'œufs typé « oeufs » — passait déjà : on
    // vérifie qu'on ne l'a pas déplacé en corrigeant l'autre.
    $oeufs = Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'M', 'unit' => 'Alvéole',
        'current_quantity' => 200, 'unit_price' => 0, 'last_unit_price' => 0, 'alert_threshold' => 0,
    ]);

    $vente = Sale::create([
        'client_id' => $this->client->id, 'user_id' => auth()->id(),
        'reference' => \App\Services\SaleNumberingService::generate('bon_livraison'),
        'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'status' => 'brouillon',
    ]);
    SaleItem::create([
        'sale_id' => $vente->id, 'product_type' => 'oeufs', 'product_name' => 'M',
        'product_id' => $oeufs->id, 'quantity' => 30, 'unit' => 'alveole',
        'unit_price' => 1000, 'total' => 30000,
    ]);
    $vente->recalculateTotals();

    app(ValidateSale::class)->execute($vente->fresh());
    expect((float) $oeufs->fresh()->current_quantity)->toBe(170.0);

    app(CancelSale::class)->execute($vente->fresh(), 'Annulée');
    expect((float) $oeufs->fresh()->current_quantity)->toBe(200.0);
});

test('un article SUPPRIMÉ entre-temps n’empêche pas l’annulation', function () {
    // On ne bride pas : la marchandise est déjà revenue, l'annulation doit
    // aboutir. C'est le SILENCE qu'on corrige, pas la permissivité.
    $article = articleHorsMapping();
    $vente = venteSur($article, $this->client->id);

    app(ValidateSale::class)->execute($vente);
    $article->delete();

    app(CancelSale::class)->execute($vente->fresh(), 'Article retiré du catalogue');

    expect($vente->fresh()->status)->toBe('annule');
});

test('la résolution de l’article n’existe qu’en UN exemplaire', function () {
    // La garde de forme. C'est la triple implémentation qui avait laissé la
    // sortie et le retour désigner deux articles différents.
    foreach (['ValidateSale', 'CancelSale', 'ProcessSaleReturn'] as $action) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path("Actions/Sale/{$action}.php")));

        expect($code)->toContain('resolveStock')
            ->and($code)->not->toContain('categoryForProductType');
    }
});

test('la table de conversion d’unités n’est plus recopiée dans les actions', function () {
    // Trois exemplaires identiques d'un même match(), donc trois occasions de
    // diverger — la même cause que le défaut principal, en plus discret.
    foreach (['ValidateSale', 'CancelSale', 'ProcessSaleReturn'] as $action) {
        $code = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', file_get_contents(app_path("Actions/Sale/{$action}.php")));

        expect($code)->not->toContain("'alveole' => 'Alvéole'")
            ->and($code)->toContain('stockInputUnit');
    }
});
