<?php

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordPayment;
use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX ENCAISSEURS, UNE SEULE RÈGLE — ET UN SEUL QUI L'APPLIQUAIT.
 *
 * `RecordPayment` refuse tout règlement supérieur au reste dû : contrôle
 * explicite, sous verrou, avec son message. C'est la règle de la maison.
 *
 * `CreateSale` encaisse aussi — le règlement comptant saisi à la vente — et
 * écrivait le `Payment` directement, sans jamais poser la question.
 *
 * Mesuré, sur une vente de 100 000 GNF réglée comptant 500 000 :
 *
 *   • par `CreateSale` : ACCEPTÉ. Vente « soldée », 400 000 GNF entrés en
 *     caisse contre rien, et un solde client de −400 000 — un avoir que
 *     personne n'a accordé ;
 *   • par `RecordPayment`, même montant, même vente : refusé.
 *
 * ─── POURQUOI CE CHEMIN-LÀ EST LE PLUS EXPOSÉ ───
 *
 * C'est celui du COMPTOIR et celui de la SYNCHRO TERRAIN : la faute de frappe
 * d'un technicien hors-ligne y arrivait sans garde, et le seul filtre en amont
 * (`immediate_payment` : `nullable|numeric|min:0`, des deux côtés) ne borne que
 * le bas.
 *
 * ─── CE QU'ON NE FAIT PAS ───
 *
 * L'application n'a aucune notion d'ACOMPTE client (pas de compte d'avances
 * distinct) : un trop-perçu n'a nulle part où se loger honnêtement. Le convertir
 * en avoir aurait été inventer une décision commerciale. `RecordPayment` avait
 * déjà tranché — on refuse — et la règle est simplement remontée sur le modèle
 * (`Sale::paymentRefusalReason`) pour que les deux encaisseurs lisent la même.
 *
 * Le refus est une `ValidationException` côté saisie : erreur de champ au
 * comptoir, et refus DÉFINITIF côté synchro (le `SyncController` la traite déjà
 * ainsi) — un rejeu ne corrigerait pas une faute de frappe.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->acheteur = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-PAY',
        'name' => 'Grossiste', 'type' => 'entreprise',
        'category' => 'grossiste', 'status' => 'actif',
    ]);
});

/**
 * Une vente d'une ligne libre (sans stock) au montant voulu, avec le règlement
 * comptant demandé. `null` = aucun règlement à la saisie.
 */
function venteComptant(int $clientId, float $montant, ?float $regle, array $extra = []): Sale
{
    return (new CreateSale())->execute(array_merge([
        'client_id' => $clientId,
        'sale_date' => today()->toDateString(),
        'type'      => 'bon_livraison',
        'tax_rate'  => 0,
        'items'     => [[
            'product_type' => 'fumier', 'product_name' => 'Fumier en vrac',
            'quantity' => 1, 'unit' => 'voyage', 'unit_price' => $montant,
        ]],
    ], $regle === null ? [] : [
        'immediate_payment' => $regle,
        'payment_method'    => 'especes',
    ], $extra));
}

test('un règlement comptant SUPÉRIEUR au dû est refusé', function () {
    /*
     * LE défaut : 500 000 encaissés sur une vente de 100 000. La vente
     * ressortait « soldée » et le client créditeur de 400 000.
     */
    expect(fn () => venteComptant($this->acheteur->id, 100_000, 500_000))
        ->toThrow(ValidationException::class, 'dépasse le reste dû');
});

test('le refus n’encaisse rien et ne crée aucune vente', function () {
    /*
     * La transaction doit retomber en entier : une vente créée puis abandonnée
     * consommerait un numéro de document, et un paiement orphelin fausserait la
     * caisse — c'est-à-dire exactement le mal qu'on corrige.
     */
    try { venteComptant($this->acheteur->id, 100_000, 500_000); } catch (ValidationException) {}

    expect(Sale::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and((float) $this->acheteur->fresh()->balance)->toBe(0.0);
});

test('les deux encaisseurs refusent le MÊME montant', function () {
    /*
     * L'égalité entre lecteurs — le test qui porte réellement la correction.
     * Une vente de 100 000 : 500 000 doit être refusé, que le règlement arrive
     * à la saisie ou après coup, avec le même motif.
     */
    $vente = venteComptant($this->acheteur->id, 100_000, null);
    (new ValidateSale())->execute($vente->fresh(['items', 'client']));

    expect(fn () => (new RecordPayment())->execute($vente->fresh(), ['amount' => 500_000]))
        ->toThrow(Exception::class, 'dépasse le reste dû');

    expect(fn () => venteComptant($this->acheteur->id, 100_000, 500_000))
        ->toThrow(ValidationException::class, 'dépasse le reste dû');
});

test('régler EXACTEMENT le dû reste permis — non-régression', function () {
    /*
     * LA borne, et c'est le geste le plus courant de tous : la vente au
     * comptoir, payée intégralement. Le point de vente passe précisément le
     * total en `immediate_payment` — une comparaison trop stricte aurait
     * cassé toute la caisse.
     */
    $vente = venteComptant($this->acheteur->id, 100_000, 100_000);

    expect($vente->fresh()->payment_status)->toBe('solde')
        ->and((float) $vente->fresh()->paid_amount)->toBe(100_000.0);
});

test('un ACOMPTE partiel reste permis — non-régression', function () {
    // La vente à crédit avec avance : le reste dû est bien le solde.
    $vente = venteComptant($this->acheteur->id, 100_000, 40_000)->fresh();

    expect((float) $vente->paid_amount)->toBe(40_000.0)
        ->and((float) $vente->remaining_amount)->toBe(60_000.0)
        ->and($vente->payment_status)->not->toBe('solde');
});

test('le dû borné est le total FINAL, remise et livraison comprises', function () {
    /*
     * La borne se lit sur `total_amount`, arrêté juste avant l'encaissement :
     * 100 000 de marchandise − 10 000 de remise + 5 000 de livraison = 95 000.
     * Payer 95 000 solde ; 100 000 — le montant de la marchandise seule — est
     * désormais un trop-perçu.
     */
    $vente = venteComptant($this->acheteur->id, 100_000, 95_000, [
        'discount_type'  => 'amount',
        'discount_value' => 10_000,
        'delivery_mode'  => 'livraison',
        'delivery_fee'   => 5_000,
    ])->fresh();

    expect((float) $vente->total_amount)->toBe(95_000.0)
        ->and($vente->payment_status)->toBe('solde');

    expect(fn () => venteComptant($this->acheteur->id, 100_000, 100_000, [
        'discount_type'  => 'amount',
        'discount_value' => 10_000,
        'delivery_mode'  => 'livraison',
        'delivery_fee'   => 5_000,
    ]))->toThrow(ValidationException::class, 'dépasse le reste dû');
});

test('une vente SANS règlement comptant reste un brouillon — non-régression', function () {
    // Le garde ne doit se poser que lorsqu'il y a de l'argent.
    $vente = venteComptant($this->acheteur->id, 100_000, null)->fresh();

    expect($vente->status)->toBe('brouillon')
        ->and((float) $vente->paid_amount)->toBe(0.0);
});
