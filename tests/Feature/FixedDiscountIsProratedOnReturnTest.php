<?php

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\ProcessSaleReturn;
use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE CLIENT RENDAIT LA MARCHANDISE ET GARDAIT TOUTE LA REMISE.
 *
 * Une reprise partielle réduit les lignes puis appelle `recalculateTotals()`,
 * qui réapplique `Sale::computeDiscount()`. Or ce calcul rend la remise TELLE
 * QUELLE quand elle est en FRANCS (`discount_type = 'amount'`) : une remise
 * consentie sur la commande entière était re-déduite en entier d'un sous-total
 * réduit.
 *
 * Mesuré, sur 100 000 GNF de marchandise remisés de 20 000 :
 *
 *   • moitié rendue → total 30 000 au lieu de 40 000. L'exploitation offre
 *     10 000 GNF de plus à chaque reprise ;
 *   • 9 sur 10 rendus → sous-total 10 000, remise plafonnée à 10 000 par
 *     `min($discount, $subtotal)` : TOTAL ZÉRO. Le reste de la commande devient
 *     gratuit, et le remboursement égale tout ce qui avait été encaissé.
 *
 * ─── CE QUI REND CE DÉFAUT REPÉRABLE ───
 *
 * La remise en POURCENTAGE se proratait déjà d'elle-même — 40 000 sur le même
 * retour, ce qu'il faut. Les deux types de remise répondaient donc
 * différemment au même geste, sur le même écran. C'est l'écart entre deux
 * lecteurs d'une même règle qui désigne lequel a tort, sans avoir à trancher
 * dans l'abstrait.
 *
 * ─── LA RÈGLE RETENUE ───
 *
 * La remise suit la marchandise CONSERVÉE. C'est la règle des avoirs : une
 * reprise partielle reprend sa part de remise, elle ne la concentre pas sur ce
 * qui reste. On aligne donc les francs sur le pourcentage, et non l'inverse.
 *
 * Le prorata est écrit sur `discount_value` plutôt que contourné dans le
 * calcul, pour que la vente PORTE la remise qu'elle applique — donc que des
 * reprises successives se composent, chacune sur l'état courant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->acheteur = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-REM',
        'name' => 'Grossiste', 'type' => 'entreprise',
        'category' => 'grossiste', 'status' => 'actif',
    ]);
});

/**
 * Une vente VALIDÉE de 10 voyages à 10 000 (100 000 de marchandise), remisée
 * comme demandé, et intégralement réglée si $paye.
 */
function venteRemisee(int $clientId, string $type, float $valeur, bool $paye = false): Sale
{
    $vente = (new CreateSale())->execute([
        'client_id'      => $clientId,
        'sale_date'      => today()->toDateString(),
        'type'           => 'bon_livraison',
        'tax_rate'       => 0,
        'discount_type'  => $type,
        'discount_value' => $valeur,
        'items'          => [[
            'product_type' => 'fumier', 'product_name' => 'Fumier en vrac',
            'quantity' => 10, 'unit' => 'voyage', 'unit_price' => 10_000,
        ]],
    ]);

    (new ValidateSale())->execute($vente->fresh(['items', 'client']));

    if ($paye) {
        $vente = $vente->fresh();
        (new \App\Actions\Sale\RecordPayment())->execute($vente, [
            'amount' => (float) $vente->total_amount,
        ]);
    }

    return $vente->fresh(['items', 'client']);
}

/** Reprend $tetes voyages sur la première ligne de la vente. */
function reprendre(Sale $vente, float $voyages): void
{
    app(ProcessSaleReturn::class)->execute(
        $vente->fresh(['items', 'client']),
        [$vente->fresh('items')->items->first()->id => $voyages],
        'Marchandise non conforme',
    );
}

test('une remise en FRANCS se prorate à la marchandise conservée', function () {
    /*
     * LE défaut : 100 000 remisés de 20 000 → 80 000. La moitié rendue laisse
     * 50 000 de marchandise, donc 10 000 de remise, donc 40 000 dus.
     * L'application facturait 30 000.
     */
    $vente = venteRemisee($this->acheteur->id, 'amount', 20_000);

    reprendre($vente, 5);

    expect((float) $vente->fresh()->total_amount)->toBe(40_000.0);
});

test('rendre presque tout ne rend pas le reste GRATUIT', function () {
    /*
     * LA borne la plus coûteuse : 9 voyages sur 10 rendus. Sous-total 10 000,
     * remise 20 000 plafonnée à 10 000 par `min($discount, $subtotal)` — total
     * ZÉRO. Le dernier voyage était offert, et le remboursement reprenait tout
     * l'encaissement.
     */
    $vente = venteRemisee($this->acheteur->id, 'amount', 20_000);

    reprendre($vente, 9);

    expect((float) $vente->fresh()->total_amount)->toBe(8_000.0);
});

test('le REMBOURSEMENT suit le total juste', function () {
    /*
     * Là où le défaut coûtait de l'argent réel : la vente est payée 80 000, la
     * moitié est rendue. On doit rembourser 40 000 — l'application en rendait
     * 50 000, soit 10 000 sortis de la caisse en trop.
     */
    $vente = venteRemisee($this->acheteur->id, 'amount', 20_000, paye: true);

    expect((float) $vente->fresh()->paid_amount)->toBe(80_000.0);

    reprendre($vente, 5);

    $apres = $vente->fresh();

    expect((float) $apres->total_amount)->toBe(40_000.0)
        ->and((float) $apres->paid_amount)->toBe(40_000.0)   // 80 000 − 40 000 remboursés
        ->and($apres->payment_status)->toBe('solde');
});

test('deux reprises successives se composent', function () {
    /*
     * La borne du choix d'implémentation : le prorata est ÉCRIT sur la vente,
     * donc la seconde reprise part de l'état courant et non de la commande
     * d'origine. 10 → 5 → 3 voyages : 30 000 de marchandise, 6 000 de remise,
     * 24 000 dus.
     */
    $vente = venteRemisee($this->acheteur->id, 'amount', 20_000);

    reprendre($vente, 5);
    reprendre($vente, 2);

    $apres = $vente->fresh();

    expect((float) $apres->subtotal)->toBe(30_000.0)
        ->and((float) $apres->discount_amount)->toBe(6_000.0)
        ->and((float) $apres->total_amount)->toBe(24_000.0);
});

test('une remise en POURCENTAGE ne bouge pas — non-régression', function () {
    /*
     * Elle se proratait déjà d'elle-même, et c'est elle qui a servi d'étalon.
     * Le correctif ne doit surtout pas la toucher deux fois.
     */
    $vente = venteRemisee($this->acheteur->id, 'percent', 20);

    reprendre($vente, 5);

    $apres = $vente->fresh();

    expect((float) $apres->total_amount)->toBe(40_000.0)
        ->and((float) $apres->discount_value)->toBe(20.0);   // le TAUX ne se prorate pas
});

test('une vente SANS remise n’est pas touchée — non-régression', function () {
    $vente = venteRemisee($this->acheteur->id, 'none', 0);

    reprendre($vente, 5);

    expect((float) $vente->fresh()->total_amount)->toBe(50_000.0);
});

test('un retour TOTAL solde la vente et éteint la remise', function () {
    // Plus de marchandise, plus de remise : le total tombe à zéro proprement.
    $vente = venteRemisee($this->acheteur->id, 'amount', 20_000);

    reprendre($vente, 10);

    $apres = $vente->fresh();

    expect((float) $apres->subtotal)->toBe(0.0)
        ->and((float) $apres->discount_amount)->toBe(0.0)
        ->and((float) $apres->total_amount)->toBe(0.0);
});
