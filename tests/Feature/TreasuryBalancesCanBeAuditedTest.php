<?php

use App\Models\Expense;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LES SOLDES DÉJÀ ÉCRITS GARDAIENT L'ERREUR DES DÉFAUTS CORRIGÉS.
 *
 * `treasury_accounts.current_balance` est une colonne STOCKÉE, mouvementée
 * incrément par incrément. Le grand-livre est la source de vérité :
 * `opening_balance + Σ entrées − Σ sorties`.
 *
 * Trois défauts les ont fait diverger, tous corrigés — le décaissement en
 * double d'un achat d'aliment rectifié (#370), les deux formules contraires de
 * la caisse (#371), le double posting sous concurrence (#372). Mais un
 * correctif ne vaut que pour l'AVENIR : rien ne recalcule spontanément un solde
 * déjà faux, et `recomputeBalance()` n'avait aucun appelant hors du module.
 *
 * ─── L'ORPHELINE, QUE LA MIGRATION NE VOIT PAS ───
 *
 * Le défaut #370 laissait une écriture pointant une pièce détruite : une sortie
 * d'argent qui n'a eu lieu qu'une fois, comptée deux.
 *
 * La migration de l'index UNIQUE (#372) dédoublonne par CLÉ. Une orpheline a
 * une clé unique — c'est un doublon de FAIT, pas de clé. Elle y survit donc
 * intacte, et il faut la chercher pour la trouver.
 *
 * ─── LA COMMANDE, ET SA CONVENTION ───
 *
 * `treasury:repair-balances` constate, puis corrige sur demande. Trois contrôles
 * dans un ordre qui compte : les orphelines d'abord, les doublons, la dérive en
 * DERNIER — pour que le recalcul tombe sur un grand-livre déjà assaini.
 *
 * Elle SIMULE par défaut, comme clients:repair-balances, eggs:repair-stock et
 * batches:rebuild-quantities : une commande qui réécrit des chiffres ne le fait
 * pas sans qu'on le lui demande.
 *
 * Et elle est planifiée le lundi SANS --force : contre-passer une écriture,
 * c'est décider qu'un mouvement d'argent n'a pas eu lieu. Ce geste demande un
 * œil humain. Ce qu'elle trouve part au journal, donc à la surveillance — un
 * contrôle planifié qui n'écrit que sur un terminal que personne ne regarde ne
 * vaut pas mieux que pas de contrôle.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->caisse = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse', 'type' => 'caisse',
        'opening_balance' => 10_000_000, 'current_balance' => 10_000_000, 'is_active' => true,
    ]);
});

/** Une dépense espèces validée : elle poste sa sortie. */
function depenseEspecesValidee(int $farmId, int $userId, float $montant = 300_000): Expense
{
    $d = Expense::create([
        'farm_id' => $farmId, 'label' => 'Gasoil', 'amount' => $montant,
        'category' => 'carburant', 'reference' => 'DEP-' . uniqid(),
        'expense_date' => now()->toDateString(), 'status' => 'en_attente',
        'payment_method' => 'especes', 'user_id' => $userId,
    ]);
    $d->update(['status' => 'valide']);

    return $d;
}

test('une trésorerie saine ne signale rien', function () {
    // La borne : le contrôle ne doit pas inventer de travail.
    depenseEspecesValidee($this->farm->id, $this->adminUser->id);

    $this->artisan('treasury:repair-balances')
        ->expectsOutputToContain('Écritures orphelines  : 0')
        ->expectsOutputToContain('Soldes dérivés        : 0')
        ->assertSuccessful();
});

test('une écriture dont la pièce a disparu est repérée', function () {
    /*
     * LA trace exacte du défaut #370, et celle que la migration de l'index
     * UNIQUE ne peut pas voir : sa clé est unique, c'est son FAIT qui est en
     * double.
     */
    $depense = depenseEspecesValidee($this->farm->id, $this->adminUser->id);

    // On détruit la pièce sans passer par le modèle — comme le faisait le défaut.
    DB::table('expenses')->where('id', $depense->id)->delete();

    $this->artisan('treasury:repair-balances')
        ->expectsOutputToContain('ORPHELINE')
        ->expectsOutputToContain('Écritures orphelines  : 1')
        ->assertSuccessful();
});

test('et sans --force, rien n’est écrit', function () {
    /*
     * LA convention du dépôt, et elle n'est pas décorative : une commande qui
     * déplace de l'argent doit pouvoir être lancée pour voir.
     */
    $depense = depenseEspecesValidee($this->farm->id, $this->adminUser->id);
    DB::table('expenses')->where('id', $depense->id)->delete();

    $this->artisan('treasury:repair-balances')->assertSuccessful();

    expect(TreasuryTransaction::count())->toBe(1)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(9_700_000.0);
});

test('avec --force, l’orpheline disparaît et le solde revient au vrai', function () {
    /*
     * L'orpheline est SUPPRIMÉE ; le solde, lui, est recalculé depuis le
     * grand-livre par le contrôle de dérive qui suit. C'est une seule passe, et
     * c'est l'ordre qui la rend juste — l'inverse figerait l'orpheline dans le
     * solde au lieu de l'en sortir.
     */
    $depense = depenseEspecesValidee($this->farm->id, $this->adminUser->id);
    DB::table('expenses')->where('id', $depense->id)->delete();

    $this->artisan('treasury:repair-balances --force')->assertSuccessful();

    expect(TreasuryTransaction::count())->toBe(0)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(10_000_000.0);
});

test('un solde dérivé est recalé sur le grand-livre', function () {
    // L'autre moitié : la colonne stockée contre la source de vérité.
    depenseEspecesValidee($this->farm->id, $this->adminUser->id);

    // On fausse le stocké, sans toucher aux écritures.
    DB::table('treasury_accounts')->where('id', $this->caisse->id)
        ->update(['current_balance' => 9_500_000]);

    $this->artisan('treasury:repair-balances --force')
        ->expectsOutputToContain('DÉRIVE')
        ->assertSuccessful();

    expect((float) $this->caisse->fresh()->current_balance)->toBe(9_700_000.0);
});

test('l’ordre compte : l’orpheline est traitée AVANT le recalcul', function () {
    /*
     * Si la dérive était traitée en premier, le recalcul tomberait sur un
     * grand-livre encore sale : il figerait l'écriture orpheline dans le solde,
     * exactement comme `recomputeBalance()` fige l'erreur qu'il est censé
     * corriger. Une seule passe doit suffire.
     */
    $depense = depenseEspecesValidee($this->farm->id, $this->adminUser->id);
    DB::table('expenses')->where('id', $depense->id)->delete();
    DB::table('treasury_accounts')->where('id', $this->caisse->id)
        ->update(['current_balance' => 9_500_000]);

    $this->artisan('treasury:repair-balances --force')->assertSuccessful();

    // Sans écriture valide, le solde vaut l'ouverture. En UNE passe.
    expect((float) $this->caisse->fresh()->current_balance)->toBe(10_000_000.0)
        ->and(TreasuryTransaction::count())->toBe(0);
});

test('elle est IDEMPOTENTE : la seconde passe ne trouve rien', function () {
    /*
     * Une commande de réparation qui n'est pas idempotente est un piège : on ne
     * peut la relancer sans risque, donc on ne la relance pas, donc elle ne sert
     * qu'une fois.
     */
    $depense = depenseEspecesValidee($this->farm->id, $this->adminUser->id);
    DB::table('expenses')->where('id', $depense->id)->delete();

    $this->artisan('treasury:repair-balances --force')->assertSuccessful();

    $this->artisan('treasury:repair-balances --force')
        ->expectsOutputToContain('Trésorerie cohérente')
        ->assertSuccessful();

    expect((float) $this->caisse->fresh()->current_balance)->toBe(10_000_000.0);
});

test('un mouvement MANUEL n’est jamais pris pour une orpheline', function () {
    /*
     * LA borne du contrôle, et le faux positif à ne pas commettre : une saisie
     * libre n'a pas de pièce (`source_type` à NULL). La traiter en orpheline
     * effacerait des mouvements parfaitement réguliers — un contrôle qui
     * détruit ce qu'il devait protéger.
     */
    TreasuryTransaction::create([
        'farm_id' => $this->farm->id, 'treasury_account_id' => $this->caisse->id,
        'direction' => 'out', 'amount' => 50_000, 'transaction_date' => now()->toDateString(),
        'category' => 'manuel', 'description' => 'Retrait',
    ]);
    $this->caisse->decrement('current_balance', 50_000);

    $this->artisan('treasury:repair-balances --force')
        ->expectsOutputToContain('Écritures orphelines  : 0')
        ->doesntExpectOutputToContain('Type de pièce inconnu')   // ni faux positif, ni bruit
        ->assertSuccessful();

    expect(TreasuryTransaction::count())->toBe(1)
        ->and((float) $this->caisse->fresh()->current_balance)->toBe(9_950_000.0);
});

test('--farm borne le contrôle à un seul site', function () {
    /*
     * En console il n'y a pas de ferme courante : la portée de ferme est inerte
     * et les quatre sites sont traités en une passe. `--farm=` permet de s'en
     * tenir à un seul — utile quand un seul site est suspect.
     */
    $autreFerme = \App\Models\Farm::create(['name' => 'Site B', 'code' => 'F-SITEB', 'is_active' => true]);

    $compteAilleurs = TreasuryAccount::create([
        'farm_id' => $autreFerme->id, 'name' => 'Caisse ailleurs', 'type' => 'caisse',
        'opening_balance' => 1_000_000, 'current_balance' => 777_000, 'is_active' => true,
    ]);

    $this->artisan('treasury:repair-balances --force --farm=' . $this->farm->id)
        ->assertSuccessful();

    // Le compte de l'autre ferme garde sa dérive : on ne l'a pas regardé.
    expect((float) $compteAilleurs->fresh()->current_balance)->toBe(777_000.0);
});
