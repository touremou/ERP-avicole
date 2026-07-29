<?php

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA PAIE DOIT LIRE LE CONTRAT.
 *
 * `generatePayroll()` payait à tout dossier « Actif » le salaire du mois entier,
 * sans jamais regarder :
 *
 *   • la date d'EMBAUCHE — un agent recruté le 25 touchait le mois complet, soit
 *     24 jours non travaillés payés ;
 *   • la date de FIN DE CONTRAT — un CDD arrivé à terme le 10 continuait d'être
 *     payé en plein, tant que sa fiche restait « Actif ». Et rien n'archive un
 *     dossier automatiquement à son terme, à raison : c'est une décision RH.
 *
 * Les deux dates existent au dossier depuis toujours. La paie ne les lisait pas.
 *
 * Le prorata est porté par une LIGNE du bulletin, et non par une modification du
 * salaire de base : le salarié voit son salaire contractuel et la retenue qui
 * l'explique.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Juin 2026 : 30 jours − 4 dimanches = 26 jours ouvrés.
    $this->period = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);
});

function hired(string $hireDate, ?string $contractEnd = null, int $salary = 260000, string $type = 'CDI'): Employee
{
    return Employee::factory()->create([
        'status' => 'Actif', 'salary' => $salary, 'contract_type' => $type,
        'hire_date' => $hireDate, 'contract_end_date' => $contractEnd,
    ]);
}

function payslipOf(Employee $employee, PayrollPeriod $period): ?Payslip
{
    return Payslip::where('employee_id', $employee->id)
        ->where('payroll_period_id', $period->id)->first();
}

test('un agent embauché en cours de mois n’est payé qu’à compter de son entrée', function () {
    // Embauche le lundi 22 juin 2026 → 22 au 30 = 8 jours ouvrés sur 26.
    $employee = hired('2026-06-22');

    (new PayrollService())->generatePayroll($this->period);
    $payslip = payslipOf($employee, $this->period);

    expect((int) $payslip->days_worked)->toBe(8);

    // Retenue = 260 000 / 26 × 18 jours non dus = 180 000.
    $prorata = PayslipLine::where('payslip_id', $payslip->id)
        ->where('category', 'prorata_contrat')->first();

    expect($prorata)->not->toBeNull()
        ->and($prorata->label)->toContain('Entrée en cours de période')
        ->and((int) $prorata->amount)->toBe(180000)
        ->and((int) $payslip->net_salary)->toBe(80000);
});

test('un CDD arrivé à terme en cours de mois n’est plus payé au-delà', function () {
    // Terme le 10 juin 2026 → 1er au 10 = 10 jours moins le dimanche 7 = 9 ouvrés.
    $employee = hired('2025-01-06', '2026-06-10', type: 'CDD');

    (new PayrollService())->generatePayroll($this->period);
    $payslip = payslipOf($employee, $this->period);

    expect((int) $payslip->days_worked)->toBe(9);

    // Retenue = 260 000 / 26 × 17 jours non dus = 170 000.
    $prorata = PayslipLine::where('payslip_id', $payslip->id)
        ->where('category', 'prorata_contrat')->first();

    expect($prorata)->not->toBeNull()
        ->and($prorata->label)->toContain('Fin de contrat')
        ->and((int) $prorata->amount)->toBe(170000)
        ->and((int) $payslip->net_salary)->toBe(90000);
});

test('le salaire de base reste le salaire CONTRACTUEL', function () {
    // On ne réécrit pas le salaire : le bulletin doit rester lisible par le
    // salarié, avec sa rémunération de référence et la retenue qui l'explique.
    $employee = hired('2026-06-22');

    (new PayrollService())->generatePayroll($this->period);

    expect((int) payslipOf($employee, $this->period)->base_salary)->toBe(260000);
});

test('un dossier hors contrat sur toute la période ne produit AUCUN bulletin', function () {
    // CDD terminé avant le début du mois, dossier encore « Actif ». Un bulletin à
    // zéro se lirait comme un salarié impayé.
    $employee = hired('2025-01-06', '2026-05-31', type: 'CDD');

    $result = (new PayrollService())->generatePayroll($this->period);

    expect(payslipOf($employee, $this->period))->toBeNull()
        ->and($result['out_of_contract'])->toBe(1)
        ->and($result['created'])->toBe(0);
});

test('un agent embauché après la fin de la période ne produit AUCUN bulletin', function () {
    $employee = hired('2026-07-05');

    $result = (new PayrollService())->generatePayroll($this->period);

    expect(payslipOf($employee, $this->period))->toBeNull()
        ->and($result['out_of_contract'])->toBe(1);
});

test('un contrat couvrant tout le mois n’est pas proratisé', function () {
    // Non-régression : le cas courant ne doit rien changer.
    $employee = hired('2024-03-01');

    (new PayrollService())->generatePayroll($this->period);
    $payslip = payslipOf($employee, $this->period);

    expect((int) $payslip->days_worked)->toBe(26)
        ->and((int) $payslip->net_salary)->toBe(260000)
        ->and(PayslipLine::where('payslip_id', $payslip->id)
            ->where('category', 'prorata_contrat')->exists())->toBeFalse();
});

test('un CDD sans terme renseigné est payé en plein, pas amputé', function () {
    // On n'invente pas de date de fin : un terme deviné amputerait un salaire.
    $employee = hired('2024-03-01', null, type: 'CDD');

    (new PayrollService())->generatePayroll($this->period);

    expect((int) payslipOf($employee, $this->period)->net_salary)->toBe(260000);
});

test('l’écran de paie annonce les dossiers hors contrat', function () {
    // Sans ce message, l'absence de bulletin passerait pour un oubli du logiciel.
    hired('2025-01-06', '2026-05-31', type: 'CDD');

    $this->actingAs($this->adminUser)
        ->post(route('payroll.generate', $this->period), ['confirm_no_attendance' => 1])
        ->assertRedirect();

    expect(session('success'))->toContain('hors contrat');
});
