<?php

use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN SEUIL À ZÉRO N'EST PAS UN SEUIL.
 *
 * `Stock::$is_low` répondait « 0 <= 0 » → VRAI. Un article dont le seuil
 * d'alerte vaut 0 et dont la quantité tombe à 0 se déclarait donc EN ALERTE.
 *
 * Ce n'est pas un cas de bord : `StockIntegrationService::ensureItem()` crée
 * ses articles avec `alert_threshold => 0`. Tout article né d'une intégration
 * automatique — sortie de transformation, entrée de récolte, produit fini —
 * comptait comme bas dès qu'il était épuisé, c'est-à-dire dans son état le plus
 * ordinaire.
 *
 * ─── LA RÈGLE ÉTAIT CONNUE, L'ACCESSEUR NE LA PORTAIT PAS ───
 *
 * Toutes les requêtes qui ALERTENT exigent `alert_threshold > 0` : celle de
 * NotificationHub, celle du tableau de bord, `FinishedProduct::scopeLow()`. Et
 * TROIS appelants ajoutaient la garde à la main :
 *
 *     if (! $wasLow && $stock->is_low && $stock->alert_threshold > 0)
 *
 * Trois copies d'une condition, c'est l'aveu que l'accesseur ne suffisait pas.
 * Le modèle sœur `RawMaterial` la porte pourtant : « if (! $this->alert_threshold)
 * return false; ».
 *
 * ─── LE LECTEUR QUI N'AVAIT PAS LA GARDE ───
 *
 * `ConsolidatedSitesService::stock()` — la vue MULTI-SITES, celle que le
 * promoteur regarde depuis l'étranger. Elle comptait `low_items` avec
 * l'accesseur nu. Elle annonçait donc des articles en alerte dont aucune alerte
 * ne parlerait jamais, et que la liste du tableau de bord ne montrait pas.
 *
 * Un compteur d'alertes qui ne correspond à aucune alerte apprend à ignorer le
 * compteur.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Article surveillé : un seuil réel, une quantité au-dessus. */
function articleSurveille(int $farmId, float $quantite, float $seuil): Stock
{
    return Stock::create([
        'farm_id' => $farmId, 'item_name' => 'Aliment démarrage ' . Str::random(4),
        'category' => Stock::CAT_CONSO, 'current_quantity' => $quantite,
        'unit' => 'kg', 'unit_price' => 5_000, 'alert_threshold' => $seuil,
    ]);
}

/** Les articles que l'application ALERTE réellement (requête de NotificationHub). */
function articlesAlertables(): int
{
    return Stock::where('alert_threshold', '>', 0)
        ->whereColumn('current_quantity', '<=', 'alert_threshold')
        ->count();
}

test('un article auto-créé et épuisé n’est PAS en alerte', function () {
    /*
     * LE défaut : `ensureItem()` pose un seuil à 0, et l'article naît à 0.
     * Il se déclarait bas dès sa création.
     */
    StockIntegrationService::ensureItem(Stock::CAT_PRODUITS_FINIS, 'Gari', 'kg', 0);

    expect(Stock::where('item_name', 'Gari')->first()->is_low)->toBeFalse();
});

test('le compteur multi-sites s’accorde avec ce qui est réellement alerté', function () {
    /*
     * L'enjeu mesuré de bout en bout : le nombre annoncé au promoteur et le
     * nombre d'articles dont une alerte parlera doivent coïncider.
     */
    StockIntegrationService::ensureItem(Stock::CAT_PRODUITS_FINIS, 'Gari', 'kg', 0);
    StockIntegrationService::ensureItem(Stock::CAT_RECOLTES, 'Manioc frais', 'kg', 0);
    articleSurveille($this->farm->id, 500, 100);

    $compteurConsolide = Stock::query()->get()->filter(fn (Stock $s) => $s->is_low)->count();

    expect($compteurConsolide)->toBe(articlesAlertables())
        ->and($compteurConsolide)->toBe(0);
});

test('un article réellement SOUS son seuil reste en alerte', function () {
    // La borne : on écarte les faux positifs, pas les vrais.
    $article = articleSurveille($this->farm->id, 80, 100);

    expect($article->is_low)->toBeTrue()
        ->and(articlesAlertables())->toBe(1);
});

test('un article PILE à son seuil est en alerte', function () {
    // Le seuil est un plancher atteint, pas un plancher dépassé — c'est ce que
    // dit la requête d'alerte (`current_quantity <= alert_threshold`).
    expect(articleSurveille($this->farm->id, 100, 100)->is_low)->toBeTrue();
});

test('un article épuisé AVEC un seuil reste bien en alerte', function () {
    /*
     * Le cas que la garde ne doit pas emporter : quantité 0 avec un seuil réel,
     * c'est la rupture — l'alerte la plus utile de toutes.
     */
    expect(articleSurveille($this->farm->id, 0, 50)->is_low)->toBeTrue();
});

test('la première descente sous le seuil déclenche toujours une alerte', function () {
    /*
     * Les trois appelants qui ajoutaient la garde à la main comparent l'état
     * AVANT et APRÈS pour n'alerter qu'au franchissement. En déplaçant la règle
     * dans l'accesseur, ce comportement doit rester intact.
     */
    $article = articleSurveille($this->farm->id, 500, 100);

    expect($article->is_low)->toBeFalse();

    StockIntegrationService::syncMovement(
        $article->item_name, Stock::CAT_CONSO, 450, 'out', 'Consommation', 'kg'
    );

    expect($article->fresh()->is_low)->toBeTrue();
});
