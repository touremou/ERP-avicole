<?php

use App\Models\Expense;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA RÈGLE ÉTAIT ÉCRITE LIGNE 124 ET ABSENTE LIGNE 204.
 *
 * `ExpenseController::update()` refuse de toucher une dépense qui n'est plus en
 * attente : « Seule une dépense en attente peut être modifiée. »
 * `ExpenseController::destroy()`, vingt lignes plus bas, ne vérifiait rien — et
 * c'est le geste le plus destructeur des deux.
 *
 * ─── MESURÉ ───
 *
 * Une dépense de 2 000 000 GNF validée le 10 juillet, supprimée en août :
 *
 *   • charges de JUILLET ........ 2 000 000 → 0
 *   • écritures de trésorerie ... 1 → 0
 *
 * Deux dégâts distincts. Le premier remonte le résultat d'un mois déjà arrêté,
 * peut-être déjà transmis. Le second est pire : `reverseFor()` SUPPRIME le
 * mouvement de caisse au lieu de le contre-passer. L'argent est bien sorti en
 * juillet, et le grand-livre dit désormais que non — et comme le solde est
 * corrigé du même geste, le contrôle de cohérence (« solde conforme au
 * grand-livre ») ne signale RIEN.
 *
 * ─── LA SORTIE EXISTAIT DÉJÀ ───
 *
 * `Expense::STATUSES` déclare trois états : en_attente, valide, ANNULE. Et
 * `ExpenseController::cancel()` est là, avec son action dédiée. L'annulation
 * conserve la pièce, son motif et son historique ; la suppression efface tout.
 * Le refus renvoie donc vers elle — un refus sans issue pousse à contourner.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'current_balance' => 10_000_000, 'is_active' => true,
    ]);
});

/** Dépense du 10 du mois dernier, dans l'état demandé. */
function depense(int $farmId, int $userId, string $statut): Expense
{
    return Expense::create([
        'farm_id' => $farmId,
        'reference' => 'DEP-' . random_int(1000, 9999),
        'category' => 'carburant',
        'label' => 'Gasoil groupe électrogène',
        'amount' => 2_000_000,
        'expense_date' => now()->subMonth()->startOfMonth()->addDays(9)->toDateString(),
        'payment_method' => 'especes',
        'status' => $statut,
        'user_id' => $userId,
    ]);
}

/** Les charges du mois dernier, telles que le rapport les affiche. */
function chargesDuMoisDernier(): float
{
    return (float) test()->get(route('reports.profit_loss', [
        'date_from' => now()->subMonth()->startOfMonth()->toDateString(),
        'date_to'   => now()->subMonth()->endOfMonth()->toDateString(),
    ]))->assertOk()->viewData('totalCosts');
}

test('supprimer une dépense VALIDÉE est refusé', function () {
    $d = depense($this->farm->id, $this->adminUser->id, 'valide');

    $this->delete(route('expenses.destroy', $d))->assertRedirect()->assertSessionHas('error');

    expect(Expense::find($d->id))->not->toBeNull();
});

test('les charges du mois arrêté ne bougent plus', function () {
    // LE défaut, chiffré : 2 000 000 → 0 sur un mois clos.
    $d = depense($this->farm->id, $this->adminUser->id, 'valide');

    expect(chargesDuMoisDernier())->toBe(2000000.0);

    $this->delete(route('expenses.destroy', $d));

    expect(chargesDuMoisDernier())->toBe(2000000.0);
});

test('le mouvement de caisse reste au grand-livre', function () {
    /*
     * Le point dur : `reverseFor()` supprime l'écriture au lieu de la
     * contre-passer, et corrige le solde du même geste — donc rien ne le
     * signale. L'argent est sorti ; le registre doit le dire.
     */
    $d = depense($this->farm->id, $this->adminUser->id, 'valide');

    $avant = TreasuryTransaction::count();
    expect($avant)->toBe(1);

    $this->delete(route('expenses.destroy', $d));

    expect(TreasuryTransaction::count())->toBe(1);
});

test('le refus renvoie vers l’annulation', function () {
    // Un refus sans issue pousse à contourner. La sortie existe : STATUSES
    // déclare « annule », et cancel() l'implémente.
    $d = depense($this->farm->id, $this->adminUser->id, 'valide');

    $this->delete(route('expenses.destroy', $d));

    expect(session('error'))->toContain('Annulez');
});

test('une dépense EN ATTENTE reste supprimable', function () {
    /*
     * La borne : une saisie erronée jamais validée n'est entrée nulle part —
     * ni au résultat, ni en caisse. La supprimer n'efface rien.
     */
    $d = depense($this->farm->id, $this->adminUser->id, 'en_attente');

    $this->delete(route('expenses.destroy', $d))->assertRedirect();

    expect(Expense::find($d->id))->toBeNull();
});

test('l’annulation, elle, reste possible sur une dépense validée', function () {
    /*
     * L'issue doit réellement fonctionner, sans quoi le refus ci-dessus
     * enfermerait l'utilisateur. La pièce survit, avec son statut.
     */
    $d = depense($this->farm->id, $this->adminUser->id, 'valide');

    $this->put(route('expenses.cancel', $d), ['reason' => 'Facture en double']);

    expect($d->fresh()->status)->toBe('annule')
        ->and(Expense::find($d->id))->not->toBeNull();
});

test('une dépense annulée quitte bien les charges', function () {
    /*
     * Le pendant : on ne fige pas le chiffre, on exige un geste tracé. Une
     * annulation légitime doit produire son effet.
     */
    $d = depense($this->farm->id, $this->adminUser->id, 'valide');

    $this->put(route('expenses.cancel', $d), ['reason' => 'Facture en double']);

    expect(chargesDuMoisDernier())->toBe(0.0);
});
