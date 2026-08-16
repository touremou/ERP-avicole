<?php

use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\TreasuryAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ALERTE ANTI-FRAUDE CRIAIT AU LOUP SUR UNE DÉPENSE ORDINAIRE.
 *
 * `expectedCash()` calculait le contenu attendu du tiroir ainsi :
 *
 *     fond de caisse + encaissements en espèces
 *
 * Les SORTIES manquaient. Or le tiroir paie aussi : le gasoil du jour, un
 * règlement fournisseur, un salaire remis en main propre — tout cela sort
 * physiquement des billets.
 *
 * ─── MESURÉ ───
 *
 * Fond 1 000 000, encaissé 500 000 en espèces, gasoil payé 300 000 :
 *
 *     réellement dans le tiroir ..... 1 200 000
 *     attendu par le code ........... 1 500 000
 *     écart annoncé ................. « MANQUANT de 300 000 »
 *
 * Et la clôture émet alors `alertCashDiscrepancy` — l'alerte anti-fraude, dont
 * le commentaire dit qu'elle porte « le signal le plus direct de détournement »
 * au promoteur, qui vit à l'étranger.
 *
 * ─── POURQUOI C'EST LE PIRE ENDROIT POUR UN FAUX POSITIF ───
 *
 * Une alerte de détournement qui se déclenche sur la routine finit par ne plus
 * être lue. Le jour où l'écart est réel, il se lit comme les autres. Ce défaut
 * n'abîmait pas un chiffre : il usait le seul signal qui compte.
 *
 * ─── LE GRAND-LIVRE PLUTÔT QUE LES SEULES DÉPENSES ───
 *
 * On lit les SORTIES du compte de caisse, ce qui couvre tous les décaissements
 * — présents et à venir — plutôt qu'une liste de types à tenir à jour.
 *
 * Deux exclusions, chacune contre un double comptage : les écritures issues
 * d'un PAIEMENT (un remboursement est déjà compté en négatif dans la somme
 * signée des encaissements), et l'écriture de CLÔTURE, qui aligne le compte sur
 * le comptant — l'inclure serait circulaire.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->caisse = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'current_balance' => 0, 'is_active' => true,
    ]);

    $this->session = CashRegisterSession::create([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id,
        'treasury_account_id' => $this->caisse->id,
        'opened_at' => now()->subHours(6), 'opening_float' => 1_000_000,
        'status' => 'open',
    ]);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-001',
        'name' => 'Client comptant', 'category' => 'detaillant', 'phone' => '620000000',
    ]);
});

/** Encaisse un montant en espèces sur une vente du jour. */
function encaisser(int $farmId, int $clientId, int $userId, float $montant): Payment
{
    $vente = Sale::create([
        'farm_id' => $farmId, 'client_id' => $clientId,
        'reference' => 'VTE-' . random_int(1000, 9999),
        'sale_date' => now()->toDateString(), 'status' => 'valide',
        'total_amount' => $montant, 'paid_amount' => 0, 'user_id' => $userId,
    ]);

    return Payment::create([
        'sale_id' => $vente->id, 'amount' => $montant, 'method' => 'especes',
        'payment_date' => now()->toDateString(), 'received_by' => $userId,
    ]);
}

/** Paie une dépense en espèces, prise sur le tiroir. */
function payerEnEspeces(int $farmId, int $userId, float $montant): Expense
{
    return Expense::create([
        'farm_id' => $farmId, 'reference' => 'DEP-' . random_int(1000, 9999),
        'category' => 'carburant', 'label' => 'Gasoil du jour', 'amount' => $montant,
        'expense_date' => now()->toDateString(), 'payment_method' => 'especes',
        'status' => 'valide', 'user_id' => $userId,
    ]);
}

test('une dépense en espèces sort bien du tiroir attendu', function () {
    /*
     * LE défaut, chiffré : 1 200 000 dans le tiroir, 1 500 000 attendus.
     */
    encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);
    payerEnEspeces($this->farm->id, $this->adminUser->id, 300_000);

    expect($this->session->fresh()->expectedCash())->toBe(1200000.0);
});

test('la clôture au comptant réel n’annonce plus d’écart', function () {
    // L'enjeu de bout en bout : le caissier compte ce qu'il a, et la caisse
    // est juste.
    encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);
    payerEnEspeces($this->farm->id, $this->adminUser->id, 300_000);

    // 1 200 000 comptés en coupures de 10 000.
    $this->post(route('cash-register.close', $this->session), [
        'counts' => [10_000 => 120],
    ])->assertRedirect();

    expect((float) $this->session->fresh()->difference)->toBe(0.0);
});

test('un VRAI manquant est toujours détecté', function () {
    /*
     * La borne qui compte le plus : on supprime le faux positif, on ne rend pas
     * l'alerte aveugle. Il manque réellement 50 000 dans le tiroir.
     */
    encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);
    payerEnEspeces($this->farm->id, $this->adminUser->id, 300_000);

    $this->post(route('cash-register.close', $this->session), [
        'counts' => [10_000 => 115],   // 1 150 000 au lieu de 1 200 000
    ]);

    expect((float) $this->session->fresh()->difference)->toBe(-50000.0);
});

test('un remboursement client n’est pas déduit DEUX fois', function () {
    /*
     * Le piège de la correction : un remboursement est un paiement NÉGATIF, donc
     * déjà sorti par la somme signée des encaissements — et il produit AUSSI une
     * écriture de sortie au grand-livre. Le compter des deux côtés ferait
     * réapparaître un manquant, à l'envers du défaut d'origine.
     */
    $paiement = encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);

    Payment::create([
        'sale_id' => $paiement->sale_id, 'amount' => -200_000, 'method' => 'especes',
        'payment_date' => now()->toDateString(), 'received_by' => $this->adminUser->id,
        'notes' => 'Remboursement retour',
    ]);

    // 1 000 000 + 500 000 − 200 000 = 1 300 000, et pas 1 100 000.
    expect($this->session->fresh()->expectedCash())->toBe(1300000.0);
});

test('une dépense payée par VIREMENT ne touche pas le tiroir', function () {
    /*
     * Seules les espèces sortent des billets. Une dépense réglée en banque ne
     * doit rien retirer du comptant attendu.
     *
     * Le compte bancaire est créé ICI et non dans le décor commun : sans lui,
     * la résolution par mode de règlement retombe sur la seule caisse active —
     * comportement correct de l'application (il faut bien poser l'écriture
     * quelque part), mais qui ferait passer ce test pour la mauvaise raison.
     */
    TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Banque',
        'type' => 'banque', 'current_balance' => 0, 'is_active' => true,
    ]);

    encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);

    Expense::create([
        'farm_id' => $this->farm->id, 'reference' => 'DEP-VIR', 'category' => 'carburant',
        'label' => 'Gasoil réglé par virement', 'amount' => 400_000,
        'expense_date' => now()->toDateString(), 'payment_method' => 'virement',
        'status' => 'valide', 'user_id' => $this->adminUser->id,
    ]);

    expect($this->session->fresh()->expectedCash())->toBe(1500000.0);
});

test('une dépense EN ATTENTE ne sort pas encore du tiroir', function () {
    /*
     * L'argent ne quitte la caisse qu'à la VALIDATION — c'est déjà la règle du
     * report en trésorerie. Une dépense saisie mais non validée ne doit pas
     * créer un manquant.
     */
    encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);

    Expense::create([
        'farm_id' => $this->farm->id, 'reference' => 'DEP-ATT', 'category' => 'carburant',
        'label' => 'Gasoil à valider', 'amount' => 300_000,
        'expense_date' => now()->toDateString(), 'payment_method' => 'especes',
        'status' => 'en_attente', 'user_id' => $this->adminUser->id,
    ]);

    expect($this->session->fresh()->expectedCash())->toBe(1500000.0);
});

test('une dépense d’AVANT l’ouverture ne compte pas', function () {
    // La session borne la fenêtre : ce qui est sorti hier n'est pas dans le
    // tiroir d'aujourd'hui.
    $ancienne = payerEnEspeces($this->farm->id, $this->adminUser->id, 300_000);
    \App\Models\TreasuryTransaction::query()->update(['created_at' => now()->subDays(3)]);

    encaisser($this->farm->id, $this->client->id, $this->adminUser->id, 500_000);

    expect($this->session->fresh()->expectedCash())->toBe(1500000.0);
});
