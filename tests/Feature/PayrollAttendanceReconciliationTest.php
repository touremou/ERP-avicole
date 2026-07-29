<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollPeriod;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * « LE RAPPORT DE PRÉSENCE NE SIGNALE RIEN, POURTANT IL Y A DÉDUCTION. »
 *
 * Les deux écrans disaient vrai, séparément — et se contredisaient à la lecture :
 *
 *   • le rapport affichait des ZÉROS partout, ce qui se lit « personne n'est
 *     venu » alors que cela signifie l'inverse : rien n'a été SAISI ;
 *   • la paie affichait des déductions, qui ne venaient pas de la présence mais
 *     des DATES DE CONTRAT (embauche en cours de mois).
 *
 * Aucune ligne de code ne reliait les deux, et rien à l'écran ne disait que la
 * paie se passe du pointage : les jours non pointés sont présumés travaillés
 * (bénéfice du doute, pour ne pas sanctionner un pointage incomplet).
 *
 * Conséquence à connaître AVANT de valider une paie : une période sans aucun
 * pointage produit exactement la même paie qu'une période où tout le monde était
 * présent tous les jours. Ce n'est pas un défaut de calcul — c'est un fait qui
 * doit être dit.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

function julyPeriod(): PayrollPeriod
{
    return PayrollPeriod::create([
        'year' => 2026, 'month' => 7, 'label' => 'Juillet 2026',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);
}

test('une période SANS pointage est signalée comme telle', function () {
    Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000, 'hire_date' => '2025-01-01',
    ]);

    $result = app(PayrollService::class)->generatePayroll(julyPeriod());

    expect($result['created'])->toBe(1)
        ->and($result['pointed_days'])->toBe(0);
});

test('le message de génération DIT que rien n’a été pointé', function () {
    // Sans cette phrase, le promoteur rapproche deux écrans qui paraissent se
    // contredire et conclut à un bug de calcul.
    Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000, 'hire_date' => '2025-01-01',
    ]);

    $period = julyPeriod();

    $this->actingAs($this->adminUser)
        ->post(route('payroll.generate', $period), ['confirm_no_attendance' => 1])
        ->assertRedirect();

    expect(session('success'))->toContain('Aucun pointage')
        // …et pourquoi les déductions ne viennent PAS de là.
        ->and(session('success'))->toContain('dates de contrat');
});

test('dès qu’un jour est pointé, l’avertissement disparaît', function () {
    // L'avertissement doit rester exceptionnel : répété à chaque paie, il ne
    // serait plus lu.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000, 'hire_date' => '2025-01-01',
    ]);

    EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'attendance_date' => '2026-07-15', 'status' => 'present',
    ]);

    $period = julyPeriod();

    $this->actingAs($this->adminUser)
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(session('success'))->not->toContain('Aucun pointage');
});

test('la déduction « entrée en cours de période » vient du CONTRAT, pas de la présence', function () {
    // Le cœur du malentendu signalé : la déduction existe alors que le rapport
    // de présence est vide, parce qu'elle ne le regarde pas.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000,
        'hire_date' => '2026-07-14',   // embauché en cours de mois
    ]);

    app(PayrollService::class)->generatePayroll(julyPeriod());

    $payslip = \App\Models\Payslip::where('employee_id', $employee->id)->firstOrFail();
    $line = $payslip->lines()->where('category', 'prorata_contrat')->first();

    expect($line)->not->toBeNull()
        ->and($line->label)->toContain('Entrée en cours de période')
        // Aucune ligne d'absence : rien n'a été pointé.
        ->and($payslip->lines()->where('category', 'absence')->count())->toBe(0);
});

test('le rapport de présence AVERTIT au lieu d’afficher des zéros muets', function () {
    Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    $response = $this->actingAs($this->adminUser)
        ->get(route('attendance.report', ['from' => '2026-07-01', 'to' => '2026-07-31']));

    $response->assertOk()
        ->assertSee(e(__('Aucun pointage enregistré sur cette période.')), false);
});

test('avec des pointages, le rapport n’avertit pas', function () {
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);

    EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'attendance_date' => '2026-07-15', 'status' => 'present',
    ]);

    $response = $this->actingAs($this->adminUser)
        ->get(route('attendance.report', ['from' => '2026-07-01', 'to' => '2026-07-31']));

    $response->assertOk()
        ->assertDontSee(e(__('Aucun pointage enregistré sur cette période.')), false);
});
