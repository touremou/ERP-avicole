<?php

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX ÉCRANS, DEUX MASSES SALARIALES POUR LE MÊME MOIS.
 *
 * `payroll_periods.total_net` est censé être la somme des nets des bulletins.
 * Il n'était écrit QUE par `PayrollPeriod::recalculateTotals()`, et cette
 * méthode n'a qu'un seul appelant dans toute l'application — la toute fin de
 * `PayrollService::generatePayroll()`.
 *
 * Or trois gestes modifient un bulletin APRÈS la génération — `addLine`,
 * `recordOvertime`, `removeLine` — et les trois n'appellent que
 * `$payslip->recalculate()`. Le bulletin bougeait, la période non.
 *
 * Et les lecteurs, eux, ne lisent pas la même chose :
 *
 *   • la LISTE des périodes (`payroll/index.blade.php`) affiche la valeur
 *     STOCKÉE — `$p->total_net` ;
 *   • le DÉTAIL de la période (`PayrollController::show`) recalcule en direct —
 *     `$period->payslips->sum('net_salary')` ;
 *   • la COMPTABILITÉ (`Accounting\PeriodCharges`) recalcule aussi en direct.
 *
 * Mesuré : après génération à 2 600 000 GNF, une prime de 500 000 saisie sur un
 * bulletin. La liste continue d'annoncer 2 600 000 ; le détail et le compte de
 * résultat annoncent 3 100 000. L'écart est permanent et vaut la somme de TOUTES
 * les primes, déductions et heures supplémentaires saisies après la génération.
 *
 * C'est aussi le chiffre que l'administrateur a sous les yeux quand il approuve :
 * depuis que la validation gèle les montants, le gel porte sur le bon total — mais
 * la liste, elle, continuait d'en afficher un autre.
 *
 * ─── OÙ LA RÈGLE EST POSÉE ───
 *
 * Pas dans les trois appelants : un quatrième oublierait, comme ces trois-là ont
 * oublié. Elle est posée là où l'écriture a lieu — `Payslip::recalculate()`, que
 * les trois appellent déjà, et par lequel passe forcément tout changement de net.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);

    Employee::factory()->create([
        'status' => 'Actif', 'salary' => 2_600_000,
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    (new PayrollService())->generatePayroll($this->periode);

    $this->bulletin = Payslip::where('payroll_period_id', $this->periode->id)->firstOrFail();
});

/** Ce que la LISTE des périodes affiche (la valeur stockée). */
function totalStocke(PayrollPeriod $periode): int
{
    return (int) $periode->fresh()->total_net;
}

/** Ce que le DÉTAIL et la comptabilité affichent (recalculé en direct). */
function totalRecalcule(PayrollPeriod $periode): int
{
    return (int) $periode->payslips()->sum('net_salary');
}

test('une prime saisie après génération met la période à jour', function () {
    /*
     * LE défaut : la liste restait à 2 600 000 quand le détail passait à
     * 3 100 000.
     */
    $this->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Prime de rendement', 'amount' => 500_000,
    ]);

    expect(totalRecalcule($this->periode))->toBe(3_100_000)
        ->and(totalStocke($this->periode))->toBe(3_100_000);
});

test('les heures supplémentaires aussi', function () {
    // Le deuxième écrivain, qui n'appelait pas davantage la période.
    $this->post(route('payroll.overtime', $this->bulletin->id), ['hours' => 10]);

    expect(totalStocke($this->periode))->toBe(totalRecalcule($this->periode))
        ->and(totalStocke($this->periode))->toBeGreaterThan(2_600_000);
});

test('et la suppression d’une ligne aussi', function () {
    // Le troisième. Retirer une déduction augmente le net tout autant.
    $this->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'deduction', 'label' => 'Avance', 'amount' => 400_000,
    ]);

    $ligne = PayslipLine::where('payslip_id', $this->bulletin->id)->firstOrFail();

    expect(totalStocke($this->periode))->toBe(2_200_000);

    $this->delete(route('payroll.remove-line', $ligne->id));

    expect(totalStocke($this->periode))->toBe(2_600_000)
        ->and(totalStocke($this->periode))->toBe(totalRecalcule($this->periode));
});

test('les primes et déductions cumulées suivent aussi', function () {
    /*
     * `total_net` n'est pas seul en cause : `total_primes` et
     * `total_deductions` sont écrits par la même méthode, et lus par les mêmes
     * écrans comparatifs.
     */
    $this->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Prime', 'amount' => 300_000,
    ]);
    $this->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'deduction', 'label' => 'Retenue', 'amount' => 100_000,
    ]);

    $periode = $this->periode->fresh();

    expect((int) $periode->total_primes)->toBe(300_000)
        ->and((int) $periode->total_deductions)->toBe(100_000)
        ->and((int) $periode->total_net)->toBe(2_800_000);
});

test('la génération pose des totaux justes — non-régression', function () {
    // Le seul appelant qui existait déjà : il doit continuer de faire son office.
    expect(totalStocke($this->periode))->toBe(2_600_000)
        ->and(totalStocke($this->periode))->toBe(totalRecalcule($this->periode));
});

test('plusieurs bulletins : la période totalise l’ensemble — non-régression', function () {
    /*
     * La borne : mettre la période à jour depuis UN bulletin ne doit pas lui
     * faire oublier les autres.
     */
    Employee::factory()->create([
        'status' => 'Actif', 'salary' => 1_400_000,
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    $seconde = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juillet 2026', 'year' => 2026, 'month' => 7,
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);

    (new PayrollService())->generatePayroll($seconde);

    $unBulletin = Payslip::where('payroll_period_id', $seconde->id)->firstOrFail();

    $this->post(route('payroll.add-line', $unBulletin->id), [
        'type' => 'prime', 'label' => 'Prime', 'amount' => 200_000,
    ]);

    expect(totalStocke($seconde))->toBe(4_000_000 + 200_000)   // 2 600 000 + 1 400 000 + prime
        ->and(totalStocke($seconde))->toBe(totalRecalcule($seconde));
});

test('la période d’à côté n’est pas touchée — non-régression', function () {
    // Un bulletin ne met à jour QUE sa propre période.
    $juillet = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juillet 2026', 'year' => 2026, 'month' => 7,
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);

    (new PayrollService())->generatePayroll($juillet);

    $avant = totalStocke($juillet);

    $this->post(route('payroll.add-line', $this->bulletin->id), [
        'type' => 'prime', 'label' => 'Prime de juin', 'amount' => 500_000,
    ]);

    expect(totalStocke($juillet))->toBe($avant)
        ->and(totalStocke($this->periode))->toBe(3_100_000);
});
