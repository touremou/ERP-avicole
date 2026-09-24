<?php

use App\Models\SaleItem;
use App\Models\Stock;

uses(Tests\TestCase::class);

/*
 * CHAQUE TYPE VENDABLE DOIT AVOIR UNE FAÇON D'ÊTRE SAISI.
 *
 * L'écran de vente range chaque ligne dans l'une de TROIS familles, et c'est
 * ce rangement qui décide de ce que l'opérateur voit :
 *
 *   • `isStockType`  → un sélecteur d'article de stock ;
 *   • `isBatchType`  → un sélecteur de lot d'animaux ;
 *   • `isManualType` → une désignation libre.
 *
 * Un type vendable qui ne tomberait dans AUCUNE des trois s'afficherait dans la
 * liste déroulante — elle, dérivée de `SELLABLE_TYPE_LABELS` — puis ne
 * proposerait rien à saisir. La ligne resterait vide, sans message.
 *
 * ─── CE QUE CE TEST N'EST PAS ───
 *
 * Il ne corrige pas un défaut constaté : au moment où il est écrit, les trois
 * familles couvrent exactement les dix types vendables, ni plus ni moins. Il
 * verrouille le point de CONTACT entre une déclaration PHP et trois listes
 * JavaScript — c'est-à-dire l'endroit précis où toute cette campagne a trouvé
 * ses défauts : deux lecteurs d'une même règle qui finissent par diverger.
 *
 * `isStockType` lisait d'ailleurs sa propre copie de `SaleItem::STOCK_TYPES` ;
 * elle lit désormais la constante. Les deux autres familles n'ont pas de
 * contrepartie PHP exacte — `BATCH_TYPES` porte en plus deux valeurs
 * historiques que le formulaire n'offre volontairement plus — et restent donc
 * déclarées à l'écran, sous la garde de ce test.
 */

/** Les trois familles telles que l'écran de vente les déclare. */
function famillesDeLEcranDeVente(): array
{
    $source = file_get_contents(resource_path('views/sales/create.blade.php'));

    $familles = [];

    foreach (['isStockType', 'isBatchType', 'isManualType'] as $famille) {
        if (preg_match("/{$famille}\(t\) \{ return (.+?)\.includes\(t\); \}/", $source, $m)) {
            $familles[$famille] = $m[1] === 'stockTypes'
                ? SaleItem::STOCK_TYPES                       // lue depuis PHP
                : json_decode(str_replace("'", '"', $m[1]), true) ?? [];
        }
    }

    return $familles;
}

test('les trois familles de l’écran de vente sont bien celles qu’on croit', function () {
    // Garde-fou du garde-fou : si les prédicats étaient renommés ou réécrits,
    // les tests suivants passeraient en ne comparant rien du tout.
    $familles = famillesDeLEcranDeVente();

    expect(array_keys($familles))->toBe(['isStockType', 'isBatchType', 'isManualType'])
        ->and(array_filter($familles))->toHaveCount(3);
});

test('tout type vendable tombe dans UNE famille — aucun n’est orphelin', function () {
    /*
     * L'invariant. Un type orphelin s'offrirait au vendeur sans rien lui
     * donner à saisir.
     */
    $couverts = array_merge(...array_values(famillesDeLEcranDeVente()));

    $orphelins = array_diff(array_keys(SaleItem::SELLABLE_TYPE_LABELS), $couverts);

    expect($orphelins)->toBe([], 'type(s) vendable(s) sans façon d’être saisi : ' . implode(', ', $orphelins));
});

test('et dans une SEULE — les familles ne se chevauchent pas', function () {
    /*
     * L'autre moitié : un type rangé dans deux familles afficherait deux
     * saisies concurrentes pour la même ligne, et c'est la dernière qui
     * gagnerait en silence.
     */
    $couverts = array_merge(...array_values(famillesDeLEcranDeVente()));

    expect($couverts)->toBe(array_unique($couverts));
});

test('un type de STOCK sait toujours dans quelle catégorie puiser', function () {
    /*
     * Le point de contact avec le stock. `getStocks` retombe sur le type
     * lui-même quand la correspondance manque, là où PHP retombe sur
     * « matériels » : deux replis différents pour une même règle — exactement
     * la forme des défauts corrigés ailleurs dans cette campagne (cycle_chair,
     * payment_delay_days).
     *
     * Ce repli est aujourd'hui INATTEIGNABLE, parce que les six types de stock
     * sont tous mappés. Ce test est ce qui le maintient inatteignable : un
     * septième type ajouté sans correspondance ferait diverger l'écran et le
     * serveur sur la catégorie à décrémenter.
     */
    $nonMappes = array_diff(SaleItem::STOCK_TYPES, array_keys(Stock::PRODUCT_TYPE_TO_CATEGORY));

    expect($nonMappes)->toBe([], 'type(s) de stock sans catégorie : ' . implode(', ', $nonMappes));
});

test('l’écran ne porte plus sa propre copie des types de stock', function () {
    /*
     * La garde qui empêche la copie de revenir. Elle était identique à la
     * constante — et rien ne l'obligeait à le rester.
     */
    $source = file_get_contents(resource_path('views/sales/create.blade.php'));

    $sansCommentaires = preg_replace(
        ['#/\*.*?\*/#s', '#//[^\n]*#', '#\{\{--.*?--\}\}#s'],
        '',
        $source,
    );

    expect($sansCommentaires)->toContain('isStockType(t) { return stockTypes.includes(t); }');
});
