<?php

use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\TreasuryAccount;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA CAISSE RENDAIT DEUX VERDICTS CONTRAIRES SUR LE MÊME TIROIR.
 *
 * Clôturer une session calcule DEUX écarts, dans la même requête, à partir de
 * deux bases différentes — et aucun des deux ne regarde l'autre.
 *
 *   • la SESSION part d'un nombre TAPÉ À LA MAIN à l'ouverture :
 *     CashRegisterSession::expectedCash() → opening_float + encaissements − sorties
 *     puis  difference = compté − expectedCash()
 *
 *   • la TRÉSORERIE part du grand-livre :
 *     CashRegisterController::syncTreasuryToCount() → delta = compté − current_balance
 *
 * Par soustraction, les deux verdicts divergent d'exactement :
 *
 *     delta − difference  =  fond tapé − solde du compte à l'ouverture
 *
 * Rien ne borne ce décalage, et rien nulle part ne compare les deux nombres.
 *
 * ─── MESURÉ ───
 *
 * Compte caisse à 800 000 GNF. Le caissier tape 500 000 comme fond. Un gasoil
 * de 300 000 est payé en espèces et validé. Le tiroir est compté : 400 000.
 *
 *   • le grand-livre attendait 500 000 (800 000 − 300 000) : le tiroir est
 *     MANQUANT DE 100 000 — c'est le fait ;
 *   • la session annonce « écart de 200 000 GNF (EXCÉDENT) ».
 *
 * Mauvais montant, mauvais SENS, sur l'écran dont c'est toute la raison d'être.
 * Et l'alerte anti-détournement envoyée au promoteur, à l'étranger, porte ce
 * nombre-là : `NotificationHub::alertCashDiscrepancy` lit `$session->difference`.
 *
 * ─── POURQUOI LE CAISSIER SE TROMPE ───
 *
 * Il ne peut pas faire autrement. Le formulaire d'ouverture code le champ en
 * dur à zéro :
 *
 *     <input type="number" name="opening_float" ... value="0" required>
 *
 * et le contrôleur ne charge même pas le solde qui aurait permis de se
 * corriger :
 *
 *     TreasuryAccount::active()->where('type','caisse')->get(['id', 'name'])
 *
 * Le système CONNAÎT le nombre, l'utilise dans la même requête pour l'autre
 * verdict, et ne le montre pas. Le geste le plus banal — ouvrir la caisse sans
 * toucher au champ — produit donc le pire résultat : le lendemain d'une
 * clôture juste, tiroir intact, la session crie un excédent égal à tout le
 * contenu du tiroir.
 *
 * ─── CE QUE ÇA COÛTE ───
 *
 * C'est le faux positif que ce dépôt a DÉJÀ corrigé une fois, par l'autre
 * moitié de la formule — le docblock de `cashPaidOut` dit pourquoi c'est le
 * pire endroit : « une alerte de détournement qui se déclenche sur la routine
 * finit par ne plus être lue, et le jour où l'écart est réel, il se lit comme
 * les autres. »
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * Le fond d'ouverture n'est pas une opinion : le grand-livre le connaît.
 *
 *   1. L'écran le PROPOSE. Le caissier garde la main — un tiroir peut
 *      réellement différer, et c'est justement l'événement qui mérite d'être
 *      saisi — mais il ne tape plus de mémoire, contre un défaut à zéro.
 *   2. Quand il passe outre, la clôture NOMME le décalage au lieu de le laisser
 *      absorber en silence par l'écriture de trésorerie. Deux verdicts qui
 *      divergent doivent le dire.
 *
 * On ne touche PAS à `expectedCash()` : la session reste un instrument de
 * comptage physique autonome, et onze fichiers de test fixent son contrat.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Un compte de caisse portant ce solde au grand-livre. */
function compteDeCaisse(int $farmId, float $solde, string $nom = 'Caisse'): TreasuryAccount
{
    return TreasuryAccount::create([
        'farm_id' => $farmId, 'name' => $nom, 'type' => 'caisse',
        'opening_balance' => $solde, 'current_balance' => $solde, 'is_active' => true,
    ]);
}

/** Une dépense espèces VALIDÉE : elle sort physiquement du tiroir. */
function gasoilPayeEnEspeces(int $farmId, int $userId, float $montant): void
{
    Expense::create([
        'farm_id' => $farmId, 'label' => 'Gasoil', 'amount' => $montant,
        'category' => 'carburant', 'reference' => 'DEP-' . uniqid(),
        'expense_date' => now()->toDateString(), 'status' => 'en_attente',
        'payment_method' => 'especes', 'user_id' => $userId,
    ])->update(['status' => 'valide']);
}

test('l’écran PROPOSE le solde du grand-livre, au lieu de zéro', function () {
    /*
     * LA cause du défaut : le champ était codé en dur à zéro, et le contrôleur
     * ne chargeait même pas la colonne qui aurait permis de le remplir.
     */
    compteDeCaisse($this->farm->id, 750_000);

    $vue = $this->get(route('cash-register.index'))->assertOk();

    $compte = $vue->viewData('caisseAccounts')->first();

    expect($compte->getAttributes())->toHaveKey('current_balance')
        ->and((float) $compte->current_balance)->toBe(750_000.0);

    $vue->assertSee('value="750000"', false);
});

test('le lendemain d’une clôture juste, le tiroir intact ne crie plus à l’excédent', function () {
    /*
     * LE geste qui faisait tout basculer, et le plus banal qui soit : rouvrir
     * la caisse sans rien changer au champ. Le tiroir n'a pas bougé, personne
     * n'a rien vendu ni dépensé — et la session annonçait un excédent égal à
     * TOUT le contenu du tiroir, alerte anti-détournement comprise.
     */
    compteDeCaisse($this->farm->id, 500_000);

    // Le caissier accepte ce que l'écran propose.
    $propose = (float) $this->get(route('cash-register.index'))
        ->viewData('caisseAccounts')->first()->current_balance;

    $this->post(route('cash-register.open'), ['opening_float' => $propose]);
    $session = CashRegisterSession::open()->firstOrFail();

    $this->post(route('cash-register.close', $session), ['counts' => [20000 => 25]]);   // 500 000

    expect((float) $session->fresh()->difference)->toBe(0.0)
        ->and(session('success'))->toContain('caisse juste');
});

test('un VRAI manquant est annoncé au bon montant et au bon sens', function () {
    /*
     * LA mesure du défaut, à l'endroit exact où il fait le plus de dégâts : le
     * tiroir est manquant de 100 000, la session annonçait « excédent de
     * 200 000 ». Mauvais montant, mauvais signe.
     */
    compteDeCaisse($this->farm->id, 800_000);

    $propose = (float) $this->get(route('cash-register.index'))
        ->viewData('caisseAccounts')->first()->current_balance;

    $this->post(route('cash-register.open'), ['opening_float' => $propose]);
    $session = CashRegisterSession::open()->firstOrFail();

    gasoilPayeEnEspeces($this->farm->id, $this->adminUser->id, 300_000);

    // Le grand-livre attend 500 000 ; on compte 400 000.
    $this->post(route('cash-register.close', $session), ['counts' => [20000 => 20]]);

    expect((float) $session->fresh()->difference)->toBe(-100_000.0)
        ->and(session('error'))->toContain('manquant');
});

test('les deux verdicts disent alors le MÊME nombre', function () {
    /*
     * L'invariant qui tient tout : `difference` (la session) et `delta` (le
     * grand-livre) mesurent le même tiroir. Leur désaccord était la maladie ;
     * leur égalité est la guérison.
     *
     * On l'éprouve par l'écriture de clôture : quand les deux s'accordent, elle
     * porte exactement l'écart annoncé.
     */
    $compte = compteDeCaisse($this->farm->id, 800_000);

    $propose = (float) $this->get(route('cash-register.index'))
        ->viewData('caisseAccounts')->first()->current_balance;

    $this->post(route('cash-register.open'), ['opening_float' => $propose]);
    $session = CashRegisterSession::open()->firstOrFail();

    gasoilPayeEnEspeces($this->farm->id, $this->adminUser->id, 300_000);

    // Le solde JUSTE AVANT la clôture : c'est la base du verdict trésorerie.
    $soldeAvant = (float) $compte->fresh()->current_balance;

    $this->post(route('cash-register.close', $session), ['counts' => [20000 => 20]]);

    $difference = (float) $session->fresh()->difference;   // verdict SESSION
    $delta      = round(400_000.0 - $soldeAvant, 2);       // verdict GRAND-LIVRE

    expect($difference)->toBe($delta)                      // l'égalité, en toutes lettres
        ->and($difference)->toBe(-100_000.0)
        ->and((float) $compte->fresh()->current_balance)->toBe(400_000.0);

    // Et l'écriture de clôture porte ce même écart, dans le bon sens.
    $ecriture = \App\Models\TreasuryTransaction::where('category', 'cloture_caisse')->sole();

    expect($ecriture->direction)->toBe('out')
        ->and((float) $ecriture->amount)->toBe(100_000.0)
        ->and($ecriture->description)->not->toContain('ouverture');
});

test('quand le caissier passe outre, le décalage est NOMMÉ', function () {
    /*
     * Le caissier garde la main : un tiroir peut réellement différer du
     * grand-livre, et c'est justement l'événement qui mérite d'être saisi. Mais
     * alors les deux verdicts divergent — et ce désaccord doit se dire, au lieu
     * d'être absorbé en silence par l'écriture de trésorerie.
     */
    compteDeCaisse($this->farm->id, 800_000);

    // Il compte le tiroir à l'ouverture et n'y trouve que 500 000.
    $this->post(route('cash-register.open'), ['opening_float' => 500_000]);
    $session = CashRegisterSession::open()->firstOrFail();

    $this->post(route('cash-register.close', $session), ['counts' => [20000 => 25]]);   // 500 000

    $message = session('error') ?? session('success');

    expect($message)->toContain('ouverture')
        ->and(\App\Models\TreasuryTransaction::where('category', 'cloture_caisse')
            ->value('description'))->toContain('ouverture');
});

test('sans compte de caisse configuré, la session reste un outil autonome — non-régression', function () {
    /*
     * La trésorerie est OPTIONNELLE : sans compte, il n'y a pas de solde à
     * proposer, et la session doit continuer de fonctionner seule. Le champ
     * retombe alors sur zéro, faute de mieux.
     */
    $this->get(route('cash-register.index'))->assertOk()->assertSee('value="0"', false);

    $this->post(route('cash-register.open'), ['opening_float' => 200_000]);
    $session = CashRegisterSession::open()->firstOrFail();

    $this->post(route('cash-register.close', $session), ['counts' => [20000 => 10]]);   // 200 000

    expect((float) $session->fresh()->difference)->toBe(0.0)
        ->and(session('success'))->toContain('caisse juste');
});

test('le caissier garde la main sur le nombre qu’il tape — non-régression', function () {
    /*
     * On PROPOSE, on n'impose pas. Ce que le caissier envoie est ce qui est
     * enregistré : l'écran ne doit pas réécrire son comptage.
     */
    compteDeCaisse($this->farm->id, 800_000);

    $this->post(route('cash-register.open'), ['opening_float' => 123_456]);

    expect((float) CashRegisterSession::open()->firstOrFail()->opening_float)->toBe(123_456.0);
});
