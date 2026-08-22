<?php

use App\Actions\Sale\CreateSale;
use App\Models\Client;
use App\Models\Provider;
use App\Models\Stock;
use App\Models\SupplierInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN DOCUMENT EST RECONNU UNE FOIS, AVEC TOUTES SES CONSÉQUENCES.
 *
 * Deux questions laissées ouvertes par #302 et #303, tranchées ici sur la
 * pratique standard. Elles n'en font qu'une : à quel moment un document
 * engage-t-il ? Et la réponse doit être la MÊME pour toutes ses conséquences.
 *
 * ─── 1. UNE VENTE ENCAISSÉE N'EST PAS UN BROUILLON ───
 *
 * L'application déclarait déjà la règle à DEUX endroits — `RecordPayment` et
 * `StorePaymentRequest` refusent d'encaisser sur un brouillon — et le point de
 * vente l'appliquait en enchaînant création, validation, livraison.
 *
 * `CreateSale` seul y dérogeait : il attachait le règlement à une vente laissée
 * en brouillon. Ni le formulaire bureau ni la synchro terrain ne validant
 * derrière, la vente restait un document qui n'engage rien tout en ayant
 * encaissé — et `CancelSale`, qui refuse d'annuler une vente porteuse de
 * paiements, la rendait impossible à nettoyer.
 *
 * C'est aussi la règle comptable : un encaissement solde une opération réalisée.
 * Un « brouillon payé » n'existe pas.
 *
 * CONSÉQUENCE ASSUMÉE : la validation déstocke et refuse si le stock manque. Une
 * vente au comptoir sur un article à zéro est donc désormais REFUSÉE, motif à
 * l'appui, là où elle devenait un brouillon payé invisible. C'est déjà ce que le
 * point de vente impose.
 *
 * ─── 2. UNE DETTE SANS CHARGE N'EXISTE PAS ───
 *
 * `SupplierInvoice::scopeCounted()` retenait « tout sauf annulé », donc les
 * brouillons : un achat simplement saisi comptait dans la dette fournisseur et
 * dans le DPO, mais PAS dans les charges du compte de résultat, qui n'arrivent
 * qu'à la validation.
 *
 * Le même document était une dette sans être une charge. En partie double, une
 * dette a forcément une contrepartie.
 *
 * On aligne sur le fait générateur que l'application avait déjà choisi — la
 * validation — plutôt que l'inverse : faire entrer les brouillons dans le
 * résultat y ferait tomber des saisies non vérifiées.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->client = Client::create([
        'farm_id'   => $this->farm->id,
        'client_id' => 'CLI-' . Str::random(6),
        'name'      => 'Boutique du marché',
        'type'      => 'entreprise',
        'category'  => 'grossiste',
        'status'    => 'actif',
    ]);
});

/** Un article de magasin approvisionné. */
function articleEnStock(int $farmId, float $quantite): Stock
{
    return Stock::create([
        'farm_id'          => $farmId,
        'item_name'        => 'Poulet entier',
        'category'         => Stock::CAT_PRODUITS_FINIS,
        'unit'             => 'piece',
        'current_quantity' => $quantite,
        'alert_threshold'  => 0,
        'unit_price'       => 2000,
        'last_unit_price'  => 2000,
    ]);
}

/** Les données d'une vente d'une pièce, avec l'encaissement voulu. */
function venteDe(int $clientId, Stock $stock, int $quantite, float $encaisse): array
{
    return [
        'client_id'         => $clientId,
        'sale_date'         => today()->toDateString(),
        'type'              => 'bon_livraison',
        'tax_rate'          => 0,
        'items'             => [[
            'product_type' => 'produits_finis',
            'product_name' => $stock->item_name,
            'product_id'   => $stock->id,
            'quantity'     => $quantite,
            'unit'         => 'piece',
            'unit_price'   => 2000,
        ]],
        'immediate_payment' => $encaisse,
        'payment_method'    => 'especes',
    ];
}

test('une vente ENCAISSÉE est validée, pas laissée en brouillon', function () {
    /*
     * La règle, dans sa forme la plus simple. L'argent est entré : le document
     * engage.
     */
    $stock = articleEnStock($this->farm->id, 50);

    $vente = (new CreateSale())->execute(venteDe($this->client->id, $stock, 10, 20_000));

    expect($vente->fresh()->status)->toBe('valide');
});

test('elle DÉSTOCKE, comme toute vente validée', function () {
    // La conséquence : le déstockage n'attend plus un second geste qui ne venait
    // jamais depuis le formulaire bureau.
    $stock = articleEnStock($this->farm->id, 50);

    $vente = (new CreateSale())->execute(venteDe($this->client->id, $stock, 10, 20_000));

    expect((float) $stock->fresh()->current_quantity)->toBe(40.0);
});

test('un ACOMPTE PARTIEL engage aussi la vente, et laisse le reste dû', function () {
    /*
     * Le cas de l'acompte : 20 000 de marchandise, 5 000 versés. La marchandise
     * part, donc la vente est engagée — et le client doit 15 000.
     */
    $stock = articleEnStock($this->farm->id, 50);

    $vente = (new CreateSale())->execute(venteDe($this->client->id, $stock, 10, 5_000));

    expect($vente->fresh()->status)->toBe('valide')
        ->and($vente->fresh()->payment_status)->toBe('partiel')
        ->and((float) $vente->fresh()->remaining_amount)->toBe(15_000.0);
});

test('une vente SANS encaissement reste un brouillon', function () {
    /*
     * La borne. On n'a pas supprimé le brouillon : une facture préparée sans
     * règlement reste modifiable, et ne déstocke pas.
     */
    $stock = articleEnStock($this->farm->id, 50);

    $vente = (new CreateSale())->execute(venteDe($this->client->id, $stock, 10, 0));

    expect($vente->fresh()->status)->toBe('brouillon')
        ->and((float) $stock->fresh()->current_quantity)->toBe(50.0);
});

test('encaisser sur un stock INSUFFISANT est refusé, avec le motif', function () {
    /*
     * LA conséquence à connaître avant de déployer. Cette vente devenait un
     * brouillon payé — argent encaissé, marchandise jamais sortie du magasin,
     * document qui n'engage rien. Elle est maintenant refusée, en disant quoi.
     */
    $stock = articleEnStock($this->farm->id, 3);

    expect(fn () => (new CreateSale())->execute(venteDe($this->client->id, $stock, 10, 20_000)))
        ->toThrow(Exception::class, 'Stock insuffisant');

    // Et rien ne subsiste : ni vente fantôme, ni argent orphelin.
    expect(\App\Models\Sale::count())->toBe(0)
        ->and(\App\Models\Payment::count())->toBe(0)
        ->and((float) $stock->fresh()->current_quantity)->toBe(3.0);
});

test('un ACHAT en brouillon n’entre PAS dans la dette fournisseur', function () {
    /*
     * La seconde décision. Le brouillon comptait dans la dette mais pas dans les
     * charges : une dette sans contrepartie.
     */
    $fournisseur = Provider::factory()->create([
        'farm_id' => $this->farm->id,
        'name'    => 'Avipro Guinée',
        'status'  => 'Actif',
    ]);

    SupplierInvoice::create([
        'farm_id'      => $this->farm->id,
        'provider_id'  => $fournisseur->id,
        'reference'    => 'ACH-' . Str::random(6),
        'invoice_date' => today()->toDateString(),
        'label'        => 'Aliment ponte',
        'category'     => 'aliment',
        'total_amount' => 500_000,
        'status'       => 'brouillon',
        'user_id'      => $this->adminUser->id,
    ]);

    expect(SupplierInvoice::counted()->count())->toBe(0)
        ->and($fournisseur->outstandingDebt())->toBe(0.0);
});

test('un ACHAT validé y entre bien', function () {
    // La borne : on écarte le brouillon, pas la vraie dette.
    $fournisseur = Provider::factory()->create([
        'farm_id' => $this->farm->id,
        'name'    => 'Avipro Guinée',
        'status'  => 'Actif',
    ]);

    SupplierInvoice::create([
        'farm_id'      => $this->farm->id,
        'provider_id'  => $fournisseur->id,
        'reference'    => 'ACH-' . Str::random(6),
        'invoice_date' => today()->toDateString(),
        'label'        => 'Aliment ponte',
        'category'     => 'aliment',
        'total_amount' => 500_000,
        'status'       => 'valide',
        'user_id'      => $this->adminUser->id,
    ]);

    expect(SupplierInvoice::counted()->count())->toBe(1)
        ->and($fournisseur->outstandingDebt())->toBe(500_000.0);
});

test('la DETTE et la CHARGE reconnaissent le même périmètre', function () {
    /*
     * L'invariant qui motivait la décision : les deux côtés du bilan doivent
     * s'accorder. La dette passe par `counted()`, la charge naît à la validation
     * — les deux excluent désormais le brouillon.
     */
    $modele = file_get_contents(base_path('app/Models/SupplierInvoice.php'));
    $tiers  = file_get_contents(base_path('app/Models/Provider.php'));

    expect(str_contains($modele, "whereNotIn('status', ['annule', 'brouillon'])"))->toBeTrue()
        ->and(str_contains($tiers, '->counted()'))
        ->toBeTrue('La dette du tiers doit passer par le scope, pas recopier le filtre.');
});
