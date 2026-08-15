<?php

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * PAYER UN BULLETIN ÉTAIT LA SEULE ÉCRITURE DE LA PAIE SANS AUCUNE GARDE.
 *
 * Tout le reste du contrôleur en a : ajouter une prime, saisir des heures
 * supplémentaires, retirer une ligne — chacun refuse si le bulletin est
 * verrouillé. `markPaid()`, qui fait SORTIR L'ARGENT, n'en avait pas une seule.
 *
 * ─── 1. LA VALIDATION DE LA PÉRIODE ÉTAIT CONTOURNABLE ───
 *
 * `validatePeriod` exige le droit `rh.S` (administrateur) : c'est le moment où
 * quelqu'un approuve la paie avant qu'elle soit versée. Mais payer se fait
 * bulletin par bulletin avec `rh.M`. Un responsable pouvait donc régler toute la
 * paie sans que la période soit jamais validée — la garde d'approbation, et le
 * droit plus élevé qu'elle exige, ne servaient à rien.
 *
 * ─── 2. UN BULLETIN DÉJÀ PAYÉ POUVAIT ÊTRE REPAYÉ ───
 *
 * `update()` réécrivait `paid_at`, le mode et la référence. La trace du
 * versement réel — quand, comment, sous quelle référence — était effacée par la
 * seconde saisie. Pour un promoteur qui suit ses versements depuis l'étranger,
 * c'est la seule preuve qu'il possède.
 *
 * ─── 3. L'ÉTAT TERMINAL N'ÉTAIT ÉCRIT PAR PERSONNE ───
 *
 * `payroll_periods.status` déclare « paye », et DEUX gardes le lisent :
 * `generate()` refuse de recalculer une période payée, `isLocked()` verrouille
 * les bulletins d'une période payée. Aucune ligne ne l'écrivait jamais : les
 * deux gardes étaient mortes, et la période restait « validée » à jamais alors
 * que tout était réglé. On la clôt quand le DERNIER bulletin est payé — le seul
 * moment où l'affirmation devient vraie.
 *
 * ─── 4. LE SALAIRE NE TOUCHAIT PAS LA TRÉSORERIE ───
 *
 * Encaissements clients, dépenses, règlements fournisseurs : tous passent en
 * trésorerie. La paie, non. Le compte Caisse ne bougeait pas d'un franc alors
 * que l'argent était sorti, et l'écart se reconstituait chaque mois, du montant
 * de la masse salariale.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse principale',
        'type' => 'caisse', 'opening_balance' => 5_000_000,
        'current_balance' => 5_000_000, 'is_active' => true,
    ]);

    $this->employe = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'salary' => 1_200_000, 'status' => 'Actif',
    ]);

    $this->periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Août 2026',
        'year' => (int) now()->year, 'month' => (int) now()->month,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => 'calcule',
    ]);

    $this->bulletin = Payslip::create([
        'payroll_period_id' => $this->periode->id, 'employee_id' => $this->employe->id,
        'base_salary' => 1_200_000, 'total_primes' => 0, 'total_deductions' => 0,
        'net_salary' => 1_200_000, 'days_worked' => 26, 'days_absent' => 0,
        'days_leave' => 0, 'overtime_hours' => 0,
        'payment_method' => 'especes', 'payment_status' => 'en_attente',
    ]);
});

/** Marque le bulletin payé par l'écran. */
function reglerBulletin(Payslip $bulletin, string $reference = 'REG-1')
{
    return test()->post(route('payroll.mark-paid', $bulletin), [
        'payment_method'    => 'especes',
        'payment_reference' => $reference,
    ]);
}

test('payer AVANT la validation de la période est refusé', function () {
    // LE défaut principal : la validation par un administrateur était
    // contournable bulletin par bulletin.
    expect($this->periode->status)->toBe('calcule');

    reglerBulletin($this->bulletin)->assertRedirect()->assertSessionHas('error');

    expect($this->bulletin->fresh()->payment_status)->toBe('en_attente');
});

test('après validation, le paiement passe', function () {
    // On ne bloque pas la paie : on rétablit l'ordre des gestes.
    $this->periode->update(['status' => 'valide']);

    reglerBulletin($this->bulletin)->assertRedirect()->assertSessionHas('success');

    expect($this->bulletin->fresh()->payment_status)->toBe('paye');
});

test('un bulletin déjà payé ne peut pas être repayé', function () {
    // Repayer effaçait la date, le mode et la référence du versement réel.
    $this->periode->update(['status' => 'valide']);

    reglerBulletin($this->bulletin, 'PREMIER');
    $premierePaie = $this->bulletin->fresh()->paid_at;

    reglerBulletin($this->bulletin, 'SECOND')->assertSessionHas('error');

    expect($this->bulletin->fresh()->payment_reference)->toBe('PREMIER')
        ->and($this->bulletin->fresh()->paid_at->toDateTimeString())->toBe($premierePaie->toDateTimeString());
});

test('le salaire versé APPARAÎT en trésorerie', function () {
    // La sortie d'argent la plus régulière de l'exploitation ne s'y voyait pas.
    $this->periode->update(['status' => 'valide']);

    reglerBulletin($this->bulletin);

    $ecriture = TreasuryTransaction::latest('id')->first();

    expect($ecriture)->not->toBeNull()
        ->and($ecriture->direction)->toBe('out')
        ->and((float) $ecriture->amount)->toBe(1200000.0)
        ->and($ecriture->category)->toBe('salaire');
});

test('le compte de caisse est diminué du net versé', function () {
    $this->periode->update(['status' => 'valide']);
    $avant = (float) TreasuryAccount::first()->current_balance;

    reglerBulletin($this->bulletin);

    expect((float) TreasuryAccount::first()->fresh()->current_balance)->toBe($avant - 1200000.0);
});

test('la période se CLÔT quand le dernier bulletin est payé', function () {
    /*
     * L'état « paye » était déclaré, lu par deux gardes, et écrit par personne.
     */
    $this->periode->update(['status' => 'valide']);

    $second = Payslip::create([
        'payroll_period_id' => $this->periode->id,
        'employee_id' => Employee::factory()->create(['farm_id' => $this->farm->id, 'salary' => 800_000])->id,
        'base_salary' => 800_000, 'total_primes' => 0, 'total_deductions' => 0,
        'net_salary' => 800_000, 'days_worked' => 26, 'days_absent' => 0,
        'days_leave' => 0, 'overtime_hours' => 0,
        'payment_method' => 'especes', 'payment_status' => 'en_attente',
    ]);

    reglerBulletin($this->bulletin);
    expect($this->periode->fresh()->status)->toBe('valide'); // il en reste un

    reglerBulletin($second);
    expect($this->periode->fresh()->status)->toBe('paye');
});

test('la clôture rend vivantes les gardes qui la lisaient', function () {
    // `generate()` refuse une période payée : cette garde ne pouvait jamais
    // s'exécuter, faute d'écrivain pour l'état qu'elle teste.
    $this->periode->update(['status' => 'valide']);
    reglerBulletin($this->bulletin);

    expect($this->periode->fresh()->status)->toBe('paye');

    $this->post(route('payroll.generate', $this->periode))
        ->assertRedirect()->assertSessionHas('error');
});

test('un bulletin d’une période payée est verrouillé', function () {
    // Second lecteur de l'état : `Payslip::isLocked()`.
    $this->periode->update(['status' => 'valide']);
    reglerBulletin($this->bulletin);

    expect($this->bulletin->fresh()->isLocked())->toBeTrue();
});

test('le paiement reste enregistré même si la trésorerie échoue', function () {
    // Jamais bloquant : perdre l'enregistrement d'un versement DÉJÀ effectué
    // serait pire que perdre son écriture comptable.
    TreasuryAccount::query()->delete();
    $this->periode->update(['status' => 'valide']);

    reglerBulletin($this->bulletin)->assertSessionHas('success');

    expect($this->bulletin->fresh()->payment_status)->toBe('paye');
});
