<?php

use App\Models\FinishedProduct;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE MAGASIN VALORISAIT LA VIANDE À CE QU'ELLE RAPPORTERA.
 *
 * `stocks.last_unit_price` est LE COÛT MOYEN PONDÉRÉ dans toute l'application :
 * `StockIntegrationService` le calcule ainsi à chaque entrée, `UpdateStockAction`
 * le nomme « le CMP, base de valorisation », `PeriodCharges` et `Batch` le lisent
 * comme un coût. `Stock::total_value` en dérive la valeur d'inventaire, et le
 * tableau de bord la ventile par catégorie.
 *
 * Le transfert abattoir → magasin y écrivait `$product->unit_price` — le PRIX DE
 * VENTE. La viande était donc valorisée à ce qu'elle rapportera, pendant que
 * tout le reste du magasin l'est à ce qu'il a coûté. Les deux se somment dans le
 * même indicateur.
 *
 * Mesuré, sur du poulet coûtant 22 000 GNF/kg à produire et vendu 35 000 :
 * 100 kg entraient à 3 500 000 GNF au lieu de 2 200 000 — l'inventaire gonflé
 * de la marge entière, soit 1 300 000 GNF sur un seul transfert.
 *
 * ─── LA BONNE GRANDEUR ÉTAIT SUR LE MODÈLE ───
 *
 * `finished_products.unit_cost` a été ajoutée précisément parce que « tout
 * produit fini entrait en stock à unit_price 0 », et elle est tenue en coût
 * moyen pondéré à chaque entrée, « propagée carcasse → découpes ». Le transfert
 * ne la lisait pas.
 *
 * ─── ET LE PRIX NE BOUGEAIT PLUS JAMAIS ───
 *
 * Il n'était posé que dans les défauts du `firstOrCreate`, donc à la PREMIÈRE
 * entrée. Un second transfert au coût de 30 000 laissait l'article à 35 000 :
 * aucun coût moyen pondéré, sur un magasin qui en tient un partout ailleurs.
 *
 * On délègue donc à `StockIntegrationService`, qui porte ce calcul — même remède
 * que pour les poussins mis en stock, et pour la même raison : recopier le
 * calcul du CMP ici en aurait fait une seconde déclaration.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);
});

/** Un produit fini de l'abattoir, avec son coût de revient et son prix de vente. */
function produitFiniAbattoir(int $farmId, float $kg, float $cout, float $prixVente): FinishedProduct
{
    return FinishedProduct::create([
        'farm_id'             => $farmId,
        'product_name'        => 'Poulet entier frais',
        'product_type'        => 'entier_frais',
        'current_quantity_kg' => $kg,
        'unit'                => 'KG',
        'unit_cost'           => $cout,
        'unit_price'          => $prixVente,
    ]);
}

/** L'article du magasin correspondant. */
function articleMagasin(string $nom = 'Poulet entier frais'): ?Stock
{
    return Stock::withoutGlobalScopes()->where('item_name', $nom)->first();
}

test('la viande entre au magasin à son COÛT, pas à son prix de vente', function () {
    /*
     * LE défaut : 100 kg à 22 000 de coût valorisés 35 000 — l'inventaire
     * gonflé de la marge entière.
     */
    $produit = produitFiniAbattoir($this->farm->id, 200, cout: 22_000, prixVente: 35_000);

    $this->post(route('slaughter.finished.transfer', $produit), ['quantity_kg' => 100])
        ->assertSessionHasNoErrors();

    $article = articleMagasin();

    expect((float) $article->last_unit_price)->toBe(22_000.0)
        ->and($article->total_value)->toBe(2_200_000.0);
});

test('deux transferts de coûts différents se mélangent au COÛT MOYEN PONDÉRÉ', function () {
    /*
     * La raison de déléguer au service : le prix n'était posé qu'à la création
     * de l'article. Un second lot, plus cher, ne déplaçait rien du tout.
     */
    $produit = produitFiniAbattoir($this->farm->id, 400, cout: 22_000, prixVente: 35_000);

    $this->post(route('slaughter.finished.transfer', $produit), ['quantity_kg' => 100]);

    $produit->update(['unit_cost' => 30_000, 'unit_price' => 40_000]);

    $this->post(route('slaughter.finished.transfer', $produit->fresh()), ['quantity_kg' => 100]);

    $article = articleMagasin();

    // (100 × 22 000 + 100 × 30 000) ÷ 200 = 26 000
    expect((float) $article->last_unit_price)->toBe(26_000.0)
        ->and((float) $article->current_quantity)->toBe(200.0);
});

test('le transfert déplace bien la matière — non-régression', function () {
    // L'abattoir se vide de ce que le magasin reçoit, et le mouvement est tracé.
    $produit = produitFiniAbattoir($this->farm->id, 200, cout: 22_000, prixVente: 35_000);

    $this->post(route('slaughter.finished.transfer', $produit), ['quantity_kg' => 120]);

    expect((float) $produit->fresh()->current_quantity_kg)->toBe(80.0)
        ->and((float) articleMagasin()->current_quantity)->toBe(120.0)
        ->and(StockMovement::withoutGlobalScopes()->where('type', 'in')->count())->toBe(1);
});

test('transférer plus que l’abattoir ne détient est refusé — non-régression', function () {
    /*
     * LA borne posée par un correctif précédent, sous verrou : la disponibilité
     * se contrôle sur la ligne verrouillée, pas sur une lecture libre.
     */
    $produit = produitFiniAbattoir($this->farm->id, 50, cout: 22_000, prixVente: 35_000);

    $this->post(route('slaughter.finished.transfer', $produit), ['quantity_kg' => 100])
        ->assertSessionHas('error');

    expect((float) $produit->fresh()->current_quantity_kg)->toBe(50.0)
        ->and(articleMagasin())->toBeNull();
});

test('un produit SANS coût de revient n’invente pas de prix', function () {
    /*
     * La borne du correctif : quand `unit_cost` est absent — un produit d'avant
     * la valorisation, ou une carcasse dont le coût n'a pas pu être calculé — on
     * n'écrit AUCUN prix plutôt que d'écrire celui de vente. Un article à zéro
     * se voit et se corrige ; un article valorisé au prix de vente se fond dans
     * l'indicateur.
     */
    $produit = produitFiniAbattoir($this->farm->id, 200, cout: 0, prixVente: 35_000);

    $this->post(route('slaughter.finished.transfer', $produit), ['quantity_kg' => 100])
        ->assertSessionHasNoErrors();

    expect((float) (articleMagasin()->last_unit_price ?? 0))->toBe(0.0);
});
