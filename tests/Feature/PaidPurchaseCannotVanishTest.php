<?php

use App\Models\Provider;
use App\Models\Sale;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TreasuryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MÊME RÈGLE, DES DEUX CÔTÉS DE L'ARGENT — ELLE N'EXISTAIT QUE D'UN.
 *
 * Côté VENTE, `CancelSale` refuse net :
 *
 *     « Impossible d'annuler : des paiements sont enregistrés sur VTE-… .
 *       Effectuez d'abord un remboursement. »
 *
 * Côté ACHAT, l'annulation ne regardait pas les règlements. Or les deux gestes
 * disent la même chose : on n'efface pas une pièce sur laquelle de l'argent a
 * déjà bougé.
 *
 * ─── CE QUE L'ANNULATION D'UN ACHAT RÉGLÉ EFFAÇAIT ───
 *
 * Le relevé fournisseur ne charge les règlements QUE des achats non annulés.
 * Annuler un achat déjà payé retirait donc du relevé, d'un seul geste, la
 * facture ET le règlement : l'argent versé au fournisseur disparaissait de son
 * compte comme s'il n'avait jamais été versé.
 *
 * Pendant ce temps la trésorerie, elle, gardait la sortie — le décaissement est
 * posté par l'observateur de règlement, que personne n'annulait. Caisse et
 * compte fournisseur cessaient de raconter la même histoire.
 *
 * Et la dépense miroir passait « annulé » : le coût sortait aussi du compte de
 * résultat. L'argent était parti, plus rien ne le portait.
 *
 * ─── LE REMÈDE EST DÉJÀ DANS LE MODULE ───
 *
 * `pay()` accepte un montant NÉGATIF — c'est l'avoir. On ne bloque donc pas une
 * situation sans issue : on renvoie vers le geste qui laisse une trace, au lieu
 * d'un effacement qui n'en laisse aucune.
 *
 * ─── ET L'HISTORIQUE DÉJÀ ANNULÉ ───
 *
 * Interdire le geste ne répare pas les achats déjà annulés en base. Le relevé
 * charge maintenant les règlements de TOUS les achats du fournisseur : un
 * règlement porté sur un achat annulé reste visible, en crédit, et le solde dit
 * alors ce qui est vrai — le fournisseur nous doit ce montant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    // Sans compte de caisse actif, aucun décaissement n'est posté et le test de
    // cohérence caisse ↔ fournisseur comparerait deux zéros.
    \App\Models\TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'current_balance' => 20_000_000, 'is_active' => true,
    ]);

    $this->fournisseur = Provider::create([
        'name' => 'Avipro Guinée SARL', 'type' => 'Aliment',
        'phone' => '620111222', 'status' => 'Actif',
    ]);
});

/** Un achat validé, prêt à être réglé. */
function achatValide(int $farmId, int $providerId, int $userId, float $montant = 5_000_000): SupplierInvoice
{
    $achat = SupplierInvoice::create([
        'farm_id' => $farmId, 'provider_id' => $providerId,
        'reference' => 'ACH-' . random_int(1000, 9999),
        'invoice_date' => now()->toDateString(),
        'category' => 'aliment', 'label' => 'Aliment ponte 40 sacs',
        'total_amount' => $montant, 'status' => 'brouillon',
        'posts_expense' => true, 'user_id' => $userId,
    ]);

    test()->put(route('purchases.validate', $achat))->assertRedirect();

    return $achat->fresh();
}

test('annuler un achat DÉJÀ RÉGLÉ est refusé', function () {
    /*
     * LE défaut. La vente refusait déjà ce geste ; l'achat le laissait passer.
     */
    $achat = achatValide($this->farm->id, $this->fournisseur->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 5_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    $this->put(route('purchases.cancel', $achat))->assertRedirect();

    expect($achat->fresh()->status)->toBe('valide');
});

test('un règlement porté sur un achat DÉJÀ annulé reste au relevé', function () {
    /*
     * L'HISTORIQUE. Interdire le geste ne répare pas les achats déjà annulés en
     * base — ceux-là existent, et leurs règlements avaient disparu du compte du
     * fournisseur.
     *
     * On reconstitue donc cet état SANS passer par l'écran (qui le refuse
     * désormais) : c'est exactement ce qu'on trouve en production.
     *
     * 5 000 000 sont sortis de la caisse. Ils doivent se lire sur le compte du
     * fournisseur, quoi qu'il advienne de la facture.
     */
    $achat = achatValide($this->farm->id, $this->fournisseur->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 5_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    $achat->update(['status' => 'annule']);   // état hérité, hors écran

    $releve = $this->get(route('purchases.statement', $this->fournisseur))->assertOk();
    $statement = $releve->viewData('statement');

    // La facture annulée n'est plus une dette : elle sort du débit. Le
    // règlement, lui, reste au crédit — et le solde dit ce qui est vrai : le
    // fournisseur nous doit ces 5 000 000.
    expect($statement['total_credit'])->toBe(5000000.0)
        ->and($statement['total_debit'])->toBe(0.0)
        ->and($statement['balance'])->toBe(-5000000.0);
});

test('le décaissement du règlement reste adossé au compte fournisseur', function () {
    /*
     * Le décaissement du RÈGLEMENT est posté par l'observateur, et personne ne
     * l'annulait : si la facture s'efface, la caisse garde une sortie que le
     * compte fournisseur ne porte plus. Les deux registres doivent s'accorder.
     *
     * On ne compare QUE les écritures issues d'un règlement fournisseur. La
     * validation d'un achat en poste une autre, par la dépense miroir — un
     * second décaissement pour le même achat, mesuré au passage ici et traité
     * à part : ce n'est pas le défaut corrigé par cette PR.
     */
    $achat = achatValide($this->farm->id, $this->fournisseur->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 5_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    $achat->update(['status' => 'annule']);   // état hérité, hors écran

    $sortiesReglement = (float) TreasuryTransaction::where('direction', 'out')
        ->where('source_type', (new SupplierPayment)->getMorphClass())
        ->sum('amount');

    $credits = $this->get(route('purchases.statement', $this->fournisseur))
        ->viewData('statement')['total_credit'];

    expect($credits)->toBe($sortiesReglement);
});

test('un achat NON réglé s’annule toujours', function () {
    /*
     * La borne : on ne durcit que le cas où de l'argent a bougé. Un achat saisi
     * par erreur, jamais payé, doit rester annulable — sinon le module se
     * remplit de dettes fantômes.
     */
    $achat = achatValide($this->farm->id, $this->fournisseur->id, $this->adminUser->id);

    $this->put(route('purchases.cancel', $achat))->assertRedirect();

    expect($achat->fresh()->status)->toBe('annule')
        ->and($achat->fresh()->expense->status)->toBe('annule');
});

test('après un AVOIR du même montant, l’annulation redevient possible', function () {
    /*
     * La sortie de secours, et la raison pour laquelle le refus n'enferme
     * personne : `pay()` accepte un montant négatif. Une fois l'argent revenu,
     * la facture peut être annulée — et la trace des deux mouvements demeure.
     */
    $achat = achatValide($this->farm->id, $this->fournisseur->id, $this->adminUser->id);

    $this->post(route('purchases.pay', $achat), [
        'amount' => 5_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
    ])->assertRedirect();

    $this->post(route('purchases.pay', $achat), [
        'amount' => -5_000_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(),
        'notes' => 'Avoir — commande non livrée',
    ])->assertRedirect();

    $this->put(route('purchases.cancel', $achat))->assertRedirect();

    expect($achat->fresh()->status)->toBe('annule')
        ->and(SupplierPayment::count())->toBe(2);
});

test('la règle est bien la même côté VENTE', function () {
    /*
     * La règle jumelle, mesurée là où elle existait déjà. Ce test n'est pas
     * décoratif : il dit d'où vient la formulation, et il tombera si quelqu'un
     * assouplit un jour le côté vente en croyant l'achat plus permissif.
     */
    $client = \App\Models\Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-900',
        'name' => 'Client comptant', 'category' => 'detaillant', 'phone' => '620999888',
    ]);

    $vente = Sale::create([
        'farm_id' => $this->farm->id, 'client_id' => $client->id,
        'reference' => 'VTE-900', 'sale_date' => now()->toDateString(),
        'status' => 'valide', 'total_amount' => 200_000, 'paid_amount' => 0,
        'user_id' => $this->adminUser->id,
    ]);

    \App\Models\Payment::create([
        'sale_id' => $vente->id, 'amount' => 200_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(), 'received_by' => $this->adminUser->id,
    ]);

    $this->put(route('sales.cancel', $vente));

    expect($vente->fresh()->status)->toBe('valide');
});
