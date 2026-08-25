<?php

use App\Actions\Dispatch\CreateDispatch;
use App\Actions\Sale\CreateSale;
use App\Models\Batch;
use App\Models\Client;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * VENDRE PUIS EXPÉDIER RETIRAIT LA MARCHANDISE DEUX FOIS.
 *
 * `ValidateSale` déstocke à la validation : articles de magasin, et effectif du
 * lot pour les animaux vendus à la tête. `CreateDispatch` faisait exactement la
 * même chose, sans jamais regarder si la vente était déjà passée par là.
 *
 * Mesuré avant correction, sur une seule opération :
 *
 *   • 100 sujets vifs vendus → 200 retirés du lot (500 → 400 → 300) ;
 *   • 50 articles vendus     → 100 retirés du magasin.
 *
 * L'effectif d'un lot est LE nombre dont dépend tout le reste : seuils de
 * mortalité, taux de ponte, aliment par sujet, marge à la clôture. Le fausser
 * fausse l'élevage entier.
 *
 * ─── #305 A RENDU LE CAS COURANT ───
 *
 * Une vente encaissée se valide désormais d'office. Tout bon de livraison émis
 * derrière décomptait donc une seconde fois — au comptoir, c'est le geste normal.
 *
 * ─── LA RÈGLE, ET SA MOITIÉ SYMÉTRIQUE ───
 *
 * Une expédition ne retire pas ce que sa vente a déjà retiré. Mais une
 * expédition SANS vente — ou dont la vente est encore un brouillon, donc jamais
 * déstockée — doit continuer de déstocker : la marchandise quitte bien la ferme,
 * et là ce geste EST le fait générateur.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-' . Str::random(6),
        'name' => 'Grossiste', 'type' => 'entreprise', 'category' => 'grossiste', 'status' => 'actif',
    ]);

    $this->lot = Batch::factory()->create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'initial_quantity' => 500, 'current_quantity' => 500, 'status' => 'Actif',
    ]);

    $this->article = Stock::create([
        'farm_id' => $this->farm->id, 'item_name' => 'Poulet entier',
        'category' => Stock::CAT_PRODUITS_FINIS, 'unit' => 'piece',
        'current_quantity' => 200, 'alert_threshold' => 0,
        'unit_price' => 2000, 'last_unit_price' => 2000,
    ]);
});

/** Une vente encaissée (donc validée, #305) de sujets vifs et d'articles. */
function venteEncaissee(int $clientId, Batch $lot, Stock $article, int $tetes, int $pieces)
{
    return (new CreateSale())->execute([
        'client_id' => $clientId,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $lot->id, 'quantity' => $tetes, 'unit' => 'tete', 'unit_price' => 30_000,
        ], [
            'product_type' => 'produits_finis', 'product_name' => $article->item_name,
            'product_id' => $article->id, 'quantity' => $pieces, 'unit' => 'piece', 'unit_price' => 2_000,
        ]],
        'immediate_payment' => 1_000,
        'payment_method'    => 'especes',
    ]);
}

/** Le bon de livraison correspondant. */
function expedition(?int $venteId, Batch $lot, Stock $article, int $tetes, int $pieces)
{
    return (new CreateDispatch())->execute([
        'sale_id'       => $venteId,
        'driver_name'   => 'Camara',
        'dispatch_date' => today()->toDateString(),
        'destination'   => 'Marché de Madina',
        'items'         => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $lot->id, 'quantity' => $tetes, 'unit' => 'tete',
        ], [
            'product_type' => 'produits_finis', 'product_name' => $article->item_name,
            'product_id' => $article->id, 'quantity' => $pieces, 'unit' => 'piece',
        ]],
    ]);
}

test('l’EFFECTIF du lot ne baisse qu’une fois', function () {
    /*
     * LE défaut, sur le nombre dont tout le reste dépend. 500 sujets, 100
     * vendus puis expédiés : il doit en rester 400, pas 300.
     */
    $vente = venteEncaissee($this->client->id, $this->lot, $this->article, 100, 50);
    expedition($vente->id, $this->lot, $this->article, 100, 50);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400);
});

test('le MAGASIN ne baisse qu’une fois non plus', function () {
    // 200 articles, 50 vendus puis expédiés : il doit en rester 150.
    $vente = venteEncaissee($this->client->id, $this->lot, $this->article, 100, 50);
    expedition($vente->id, $this->lot, $this->article, 100, 50);

    expect((float) $this->article->fresh()->current_quantity)->toBe(150.0);
});

test('une expédition SANS vente déstocke bien', function () {
    /*
     * La moitié symétrique, et elle compte autant : sans vente rattachée,
     * l'expédition EST le fait générateur. Ne plus déstocker ferait disparaître
     * la sortie de marchandise.
     */
    expedition(null, $this->lot, $this->article, 100, 50);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400)
        ->and((float) $this->article->fresh()->current_quantity)->toBe(150.0);
});

test('une expédition sur une vente en BROUILLON déstocke aussi', function () {
    /*
     * Un brouillon n'a jamais déstocké : l'expédition reste donc le seul geste
     * qui sorte la marchandise.
     */
    $vente = (new CreateSale())->execute([
        'client_id' => $this->client->id,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'animal_vif', 'product_name' => 'Poulet vif',
            'batch_id' => $this->lot->id, 'quantity' => 100, 'unit' => 'tete', 'unit_price' => 30_000,
        ], [
            'product_type' => 'produits_finis', 'product_name' => $this->article->item_name,
            'product_id' => $this->article->id, 'quantity' => 50, 'unit' => 'piece', 'unit_price' => 2_000,
        ]],
        'immediate_payment' => 0,   // pas d'encaissement → reste brouillon
    ]);

    expect($vente->fresh()->status)->toBe('brouillon')
        ->and((int) $this->lot->fresh()->current_quantity)->toBe(500);

    expedition($vente->id, $this->lot, $this->article, 100, 50);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400)
        ->and((float) $this->article->fresh()->current_quantity)->toBe(150.0);
});

test('la vente seule, sans expédition, déstocke toujours', function () {
    /*
     * La borne de non-régression : le client qui emporte sa marchandise au
     * comptoir n'émet aucun bon de livraison. #305 doit continuer d'opérer.
     */
    venteEncaissee($this->client->id, $this->lot, $this->article, 100, 50);

    expect((int) $this->lot->fresh()->current_quantity)->toBe(400)
        ->and((float) $this->article->fresh()->current_quantity)->toBe(150.0);
});
