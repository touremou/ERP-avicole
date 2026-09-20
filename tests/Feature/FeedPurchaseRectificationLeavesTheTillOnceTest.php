<?php

use App\Actions\FeedPurchase\CreateFeedPurchase;
use App\Actions\FeedPurchase\UpdateFeedPurchase;
use App\Models\Batch;
use App\Models\FeedPurchase;
use App\Models\SupplierPayment;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RECTIFIER UN ACHAT D'ALIMENT COMPTANT SORTAIT L'ARGENT UNE SECONDE FOIS.
 *
 * Corriger un achat déjà réglé re-cale son règlement sur le nouveau montant —
 * geste juste, et c'est ce que fait `UpdateFeedPurchase::syncSupplierInvoice` :
 *
 *     if ($wasPaid) {
 *         $invoice->payments()->delete();      // l'ancien règlement
 *         SupplierPayment::create([...]);      // le nouveau
 *     }
 *
 * Le remplacement est correct au registre fournisseur. Il ne l'est pas en
 * caisse, et pour une raison qui ne se voit pas en lisant ces deux lignes.
 *
 * ─── POURQUOI LA CAISSE NE SUIT PAS ───
 *
 * `$invoice->payments()->delete()` est une suppression par le CONSTRUCTEUR DE
 * REQUÊTE : un seul DELETE en base, aucun modèle chargé, donc aucun événement
 * Eloquent. `SupplierPaymentObserver::deleted` ne s'exécute jamais, et avec lui
 * `TreasuryPostingService::reverseFor` — la contre-passation qui devait rendre
 * l'argent à la caisse.
 *
 * L'écriture de décaissement reste donc en place, orpheline : sa pièce
 * d'origine n'existe plus. Et le règlement recréé porte un identifiant NEUF,
 * qu'aucune écriture ne désigne — `alreadyPosted()` ne voit rien, et poste une
 * seconde sortie.
 *
 * ─── MESURÉ ───
 *
 * Un achat d'aliment de 500 000 GNF réglé en espèces, rectifié à 650 000 :
 *
 *   • sorties de caisse : 1 150 000 GNF (500 000 + 650 000) ;
 *   • une écriture orpheline, pointant un règlement supprimé ;
 *   • la facture, elle, dit 650 000 — le registre fournisseur est juste.
 *
 * Et ce n'est pas une affaire de montant changé : rectifier le seul LIBELLÉ,
 * à montant inchangé, sort 1 000 000 pour un achat de 500 000. Le bloc entier
 * est commandé par `if ($wasPaid)`, qui ne demande jamais si le total a bougé.
 *
 * ─── CE QUE ÇA COÛTE, ET POURQUOI ÇA NE SE RATTRAPE PAS ───
 *
 * `recomputeBalance()` — le recours quand un solde paraît faux — RECALCULE à
 * partir de ces mêmes écritures. Il confirme donc l'erreur au lieu de la
 * corriger. Une caisse rapprochée à la main ne retrouvera jamais ses comptes :
 * il manque un montant qui n'a de trace nulle part ailleurs.
 *
 * ─── UN SECOND DÉCAISSEMENT FAUX, DANS LE MÊME BLOC ───
 *
 * En mesurant le premier, un autre est apparu : le règlement était recréé
 * « especes », payé par celui qui rectifie. Un achat pris à crédit puis réglé
 * PAR VIREMENT — le geste normal de l'écran de règlement — devenait donc un
 * règlement en espèces.
 *
 * Mesuré, sur le même achat de 500 000 GNF réglé par virement, rectifié au seul
 * libellé :
 *
 *   • banque : 49 500 000 — débitée, et jamais recréditée ;
 *   • caisse : 9 500 000 — débitée à son tour, pour un argent qui n'en est
 *     jamais sorti.
 *
 * Deux comptes faux, en sens inverses, pour un achat payé une fois. Et réparer
 * le premier défaut sans celui-ci n'aurait fait que déplacer la sortie sur le
 * mauvais compte, proprement.
 *
 * ─── LES RÈGLES POSÉES ───
 *
 *   1. Une pièce de règlement qu'on remplace se supprime PAR LE MODÈLE, pour
 *      que la contre-passation parte.
 *   2. On rectifie le MONTANT. Le mode, le compte, le payeur et le motif sont
 *      des faits que le formulaire d'achat ne connaît pas. La date suit l'achat
 *      seulement si le règlement était daté du jour de l'achat — c'est alors le
 *      règlement fait à l'achat.
 *   3. Un SEUL règlement se re-cale. Plusieurs sont des versements distincts :
 *      les fondre en une pièce unique détruirait des faits que la rectification
 *      d'un achat n'a pas à arbitrer.
 *
 * Le test le plus important de ce fichier est l'avant-dernier : ZÉRO écriture ne
 * doit désigner un règlement qui n'existe plus. C'est lui qui distingue une
 * vraie correction d'un replâtrage — un retour aux SoftDeletes, par exemple,
 * laisserait la suppression par requête aussi muette qu'avant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    /*
     * LE PIÈGE DE CE DÉCOR : sans compte de caisse actif, `postSupplierPayment`
     * ressort sur un `Log::info` et n'écrit rien. Le test comparerait alors deux
     * zéros et resterait VERT avec le défaut en place — c'est exactement ce qui
     * arrive à `FeedPurchaseApUnificationTest`, qui couvre le même geste.
     */
    $this->caisse = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'current_balance' => 20_000_000, 'is_active' => true,
    ]);

    $this->batch = Batch::factory()->create(['status' => 'Actif']);
});

/** Un achat d'aliment réglé comptant. `unit_price` porte le montant TOTAL. */
function achatComptant(int $batchId, float $montant): FeedPurchase
{
    (new CreateFeedPurchase())->execute([
        'batch_id'      => $batchId,
        'purchase_date' => now()->toDateString(),
        'feed_type'     => 'Maïs concassé',
        'quantity'      => 10,
        'unit_price'    => $montant,
        'supplier'      => 'Provende Express',
        'unit'          => 'Sac',
    ]);

    return FeedPurchase::firstOrFail();
}

/** Un achat d'aliment pris à CRÉDIT : la facture reste à régler. */
function achatACredit(int $batchId, float $montant): FeedPurchase
{
    (new CreateFeedPurchase())->execute([
        'batch_id'      => $batchId,
        'purchase_date' => now()->toDateString(),
        'feed_type'     => 'Soja',
        'quantity'      => 8,
        'unit_price'    => $montant,
        'supplier'      => 'Crédit Aliments',
        'unit'          => 'Sac',
        'payment_mode'  => 'credit',
    ]);

    return FeedPurchase::firstOrFail();
}

/** Le geste de l'écran de règlement : un versement par virement bancaire. */
function reglerParVirement(float $montant, int $payeurId): SupplierPayment
{
    return SupplierPayment::create([
        'supplier_invoice_id' => \App\Models\SupplierInvoice::firstOrFail()->id,
        'amount'              => $montant,
        'payment_date'        => now()->toDateString(),
        'method'              => 'virement',
        'notes'               => 'Virement bancaire',
        'paid_by'             => $payeurId,
    ]);
}

/** Total des sorties enregistrées, TOUS comptes de trésorerie confondus. */
function totalDesSorties(): float
{
    return (float) TreasuryTransaction::where('direction', 'out')->sum('amount');
}

test('rectifier le montant d’un achat réglé ne sort l’argent qu’une fois', function () {
    /*
     * LE défaut : 1 150 000 GNF sortis pour un achat de 650 000.
     */
    $achat = achatComptant($this->batch->id, 500_000);

    expect(totalDesSorties())->toBe(500_000.0);   // le décaissement d'origine

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Maïs concassé', 'quantity' => 10, 'unit_price' => 650_000,
        'supplier' => 'Provende Express', 'purchase_date' => now()->toDateString(),
    ]);

    expect(totalDesSorties())->toBe(650_000.0);
});

test('et la caisse n’est débitée que du montant rectifié', function () {
    /*
     * Le solde est tenu à part des écritures : il faut l'éprouver pour lui-même,
     * sinon une contre-passation qui supprime l'écriture sans rendre l'argent
     * passerait le test précédent.
     */
    $achat = achatComptant($this->batch->id, 500_000);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Maïs concassé', 'quantity' => 10, 'unit_price' => 650_000,
        'supplier' => 'Provende Express', 'purchase_date' => now()->toDateString(),
    ]);

    expect((float) $this->caisse->fresh()->current_balance)->toBe(20_000_000.0 - 650_000.0);
});

test('rectifier le seul LIBELLÉ, à montant inchangé, ne sort rien de plus', function () {
    /*
     * Le défaut ne dépend pas d'un montant qui bouge : le bloc est commandé par
     * `if ($wasPaid)`, qui ne demande jamais si le total a changé. Corriger un
     * nom de fournisseur mal orthographié sortait un second montant PLEIN.
     */
    $achat = achatComptant($this->batch->id, 500_000);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Maïs concassé', 'quantity' => 10, 'unit_price' => 500_000,
        'supplier' => 'Provende Express SARL', 'purchase_date' => now()->toDateString(),
    ]);

    expect(totalDesSorties())->toBe(500_000.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20_000_000.0 - 500_000.0);
});

test('un achat à CRÉDIT rectifié ne sort toujours rien — non-régression', function () {
    /*
     * La borne de l'autre côté : une dette n'est pas un décaissement. Le bloc
     * ne s'exécute pas, et rectifier ne doit pas l'ouvrir.
     */
    (new CreateFeedPurchase())->execute([
        'batch_id' => $this->batch->id, 'purchase_date' => now()->toDateString(),
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 400_000,
        'supplier' => 'Crédit Aliments', 'unit' => 'Sac', 'payment_mode' => 'credit',
    ]);

    (new UpdateFeedPurchase())->execute(FeedPurchase::firstOrFail(), [
        'feed_type' => 'Soja', 'quantity' => 9, 'unit_price' => 450_000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
    ]);

    expect(totalDesSorties())->toBe(0.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20_000_000.0);
});

test('le registre fournisseur reste juste — non-régression', function () {
    /*
     * On corrige la caisse, on ne casse pas ce qui marchait : la facture suit le
     * nouveau montant et reste soldée. C'est ce que couvrait déjà
     * `FeedPurchaseApUnificationTest`, et qui doit continuer de tenir.
     */
    $achat = achatComptant($this->batch->id, 500_000);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Maïs concassé', 'quantity' => 10, 'unit_price' => 650_000,
        'supplier' => 'Provende Express', 'purchase_date' => now()->toDateString(),
    ]);

    $facture = \App\Models\SupplierInvoice::firstOrFail();

    expect((float) $facture->total_amount)->toBe(650_000.0)
        ->and($facture->payment_status)->toBe('solde')
        ->and((float) $facture->paid_amount)->toBe(650_000.0);
});

test('un achat réglé par VIREMENT reste réglé par virement', function () {
    /*
     * LE second défaut : la banque restait débitée sans retour, et la caisse
     * était débitée à son tour. Réparer la contre-passation sans corriger ceci
     * n'aurait fait que déplacer la sortie sur le mauvais compte, proprement.
     */
    $banque = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Banque',
        'type' => 'banque', 'current_balance' => 50_000_000, 'is_active' => true,
    ]);

    $achat = achatACredit($this->batch->id, 500_000);
    reglerParVirement(500_000, $this->adminUser->id);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 500_000,
        'supplier' => 'Crédit Aliments SARL', 'purchase_date' => now()->toDateString(),
    ]);

    expect(SupplierPayment::sole()->method)->toBe('virement')
        ->and((float) $banque->fresh()->current_balance)->toBe(50_000_000.0 - 500_000.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20_000_000.0);
});

test('et le montant rectifié sort bien de la banque, pas de la caisse', function () {
    // Le même geste avec un total qui bouge : c'est la BANQUE qui suit.
    $banque = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Banque',
        'type' => 'banque', 'current_balance' => 50_000_000, 'is_active' => true,
    ]);

    $achat = achatACredit($this->batch->id, 500_000);
    reglerParVirement(500_000, $this->adminUser->id);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 650_000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
    ]);

    expect((float) $banque->fresh()->current_balance)->toBe(50_000_000.0 - 650_000.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20_000_000.0)
        ->and(totalDesSorties())->toBe(650_000.0);
});

test('le payeur reste celui qui a payé, pas celui qui rectifie', function () {
    /*
     * Une piste d'audit qui se réécrit toute seule ne vaut rien : le règlement
     * portait le nom de la dernière personne à avoir corrigé la fiche.
     */
    $caissier = \App\Models\User::factory()->create();

    $achat = achatACredit($this->batch->id, 500_000);
    reglerParVirement(500_000, $caissier->id);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 650_000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
    ]);

    expect(SupplierPayment::sole()->paid_by)->toBe($caissier->id);
});

test('un virement fait PLUS TARD garde sa propre date', function () {
    /*
     * La date d'un règlement est le jour où l'argent est sorti — c'est elle que
     * porte le relevé bancaire, et elle ne se déduit pas de la date d'achat.
     * La re-caler sur l'achat déplacerait l'écriture d'un compte réel sur un
     * jour où rien ne s'y est passé, et le rapprochement ne tomberait plus.
     */
    TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Banque',
        'type' => 'banque', 'current_balance' => 50_000_000, 'is_active' => true,
    ]);

    $achat = achatACredit($this->batch->id, 500_000);   // acheté aujourd'hui

    $reglement = reglerParVirement(500_000, $this->adminUser->id);
    $reglement->update(['payment_date' => now()->addDays(12)->toDateString()]);

    // On corrige la date d'achat : le virement, lui, a bien eu lieu J+12.
    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 650_000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->subDays(3)->toDateString(),
    ]);

    expect(SupplierPayment::sole()->payment_date->toDateString())
        ->toBe(now()->addDays(12)->toDateString())
        ->and(TreasuryTransaction::where('direction', 'out')->sole()->transaction_date->toDateString())
        ->toBe(now()->addDays(12)->toDateString());
});

test('mais le règlement fait À L’ACHAT suit la date rectifiée', function () {
    /*
     * L'autre moitié de la même règle, et le cas courant : l'achat comptant se
     * règle le jour de l'achat. Corriger une date d'achat mal saisie doit
     * emporter son règlement, sinon les deux se contredisent.
     */
    $achat = achatComptant($this->batch->id, 500_000);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Maïs concassé', 'quantity' => 10, 'unit_price' => 500_000,
        'supplier' => 'Provende Express', 'purchase_date' => now()->subDays(3)->toDateString(),
    ]);

    expect(SupplierPayment::sole()->payment_date->toDateString())
        ->toBe(now()->subDays(3)->toDateString());
});

test('le motif du règlement n’est pas réécrit à contresens', function () {
    /*
     * Le motif était remplacé par « Réglé à l'achat (aliment) » — ce qui, pour
     * un virement bancaire fait trois semaines plus tard, dit le contraire de
     * ce qui s'est passé. On garde le sien, marqué UNE fois : deux
     * rectifications successives ne doivent pas empiler les marques.
     */
    $achat = achatACredit($this->batch->id, 500_000);
    reglerParVirement(500_000, $this->adminUser->id);

    foreach ([650_000, 700_000] as $montant) {
        (new UpdateFeedPurchase())->execute($achat->fresh(), [
            'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => $montant,
            'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
        ]);
    }

    expect(SupplierPayment::sole()->notes)->toBe('Virement bancaire — rectifié');
});

test('un règlement rattaché à un compte PRÉCIS y reste rattaché', function () {
    /*
     * ─── CE TEST NE PASSE PAS PAR UN ÉCRAN, ET C'EST DÉLIBÉRÉ ───
     *
     * `supplier_payments.treasury_account_id` désigne le compte exact d'où
     * l'argent est sorti, et `TreasuryPostingService::resolveAccount` le lit en
     * priorité sur le mode de paiement. Mais AUCUN des trois chemins qui créent
     * un règlement fournisseur ne l'écrit aujourd'hui — colonne lue, jamais
     * remplie. On ne peut donc pas l'éprouver par la vraie porte : il n'y en a
     * pas encore.
     *
     * On pose quand même la règle ici, en écrivant la colonne directement. Sans
     * ce test, la ligne qui reporte le compte est invisible : elle recopie null
     * sur null, et rien ne la protège. Le jour où un écran laissera choisir
     * entre deux caisses — le cas qui a motivé la colonne — la rectification
     * enverrait la contre-passation et le nouveau décaissement sur la PREMIÈRE
     * caisse venue, pas sur celle qui a payé.
     */
    $caisseBoutique = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse boutique',
        'type' => 'caisse', 'current_balance' => 3_000_000, 'is_active' => true,
    ]);

    $achat = achatACredit($this->batch->id, 500_000);

    SupplierPayment::create([
        'supplier_invoice_id' => \App\Models\SupplierInvoice::firstOrFail()->id,
        'amount'              => 500_000,
        'payment_date'        => now()->toDateString(),
        'method'              => 'especes',
        'treasury_account_id' => $caisseBoutique->id,   // payé à la boutique
        'paid_by'             => $this->adminUser->id,
    ]);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 650_000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
    ]);

    expect(SupplierPayment::sole()->treasury_account_id)->toBe($caisseBoutique->id)
        ->and((float) $caisseBoutique->fresh()->current_balance)->toBe(3_000_000.0 - 650_000.0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(20_000_000.0);
});

test('PLUSIEURS versements ne sont pas fondus en une seule pièce', function () {
    /*
     * Deux acomptes saisis un par un à l'écran de règlement sont deux faits.
     * Les remplacer par une pièce unique effacerait deux dates, deux modes et
     * deux montants réels — un arbitrage que la rectification d'un achat n'a
     * pas à porter. La facture dit alors ce qui est vrai : partiellement réglée
     * au nouveau total.
     */
    $achat = achatACredit($this->batch->id, 500_000);
    reglerParVirement(200_000, $this->adminUser->id);
    reglerParVirement(300_000, $this->adminUser->id);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Soja', 'quantity' => 8, 'unit_price' => 650_000,
        'supplier' => 'Crédit Aliments', 'purchase_date' => now()->toDateString(),
    ]);

    $facture = \App\Models\SupplierInvoice::firstOrFail();

    expect(SupplierPayment::count())->toBe(2)
        ->and((float) $facture->paid_amount)->toBe(500_000.0)
        ->and($facture->payment_status)->toBe('partiel')
        ->and((float) $facture->remaining_amount)->toBe(150_000.0);
});

test('aucune écriture de caisse ne désigne un règlement qui n’existe plus', function () {
    /*
     * L'INVARIANT, et le seul test qui distingue une vraie correction d'un
     * replâtrage. Une écriture orpheline est pire qu'un solde faux : elle est
     * irrattrapable, parce que `recomputeBalance()` recalcule À PARTIR d'elle et
     * fige l'erreur au lieu de la corriger.
     *
     * Le repasser aux SoftDeletes ne suffirait pas : une suppression par le
     * constructeur de requête reste muette pour l'observateur.
     */
    $achat = achatComptant($this->batch->id, 500_000);

    (new UpdateFeedPurchase())->execute($achat, [
        'feed_type' => 'Maïs concassé', 'quantity' => 10, 'unit_price' => 650_000,
        'supplier' => 'Provende Express', 'purchase_date' => now()->toDateString(),
    ]);

    $vivants = SupplierPayment::pluck('id');

    $orphelines = TreasuryTransaction::where('source_type', (new SupplierPayment())->getMorphClass())
        ->whereNotIn('source_id', $vivants)
        ->count();

    expect($orphelines)->toBe(0);
});
