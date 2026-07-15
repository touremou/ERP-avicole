<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    // Juin 2026 : 30 j − 4 dimanches = 26 jours ouvrés.
    $this->period = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);
});

test('la génération de paie déduit les absences réellement pointées', function () {
    $e = Employee::factory()->create(['status' => 'Actif', 'salary' => 260000]);

    foreach (['2026-06-10', '2026-06-11'] as $d) { // 2 jours ouvrés
        EmployeeAttendance::create([
            'farm_id' => $this->farm->id, 'employee_id' => $e->id,
            'attendance_date' => $d, 'status' => 'absent',
        ]);
    }

    (new PayrollService())->generatePayroll($this->period);

    $payslip = Payslip::where('employee_id', $e->id)->where('payroll_period_id', $this->period->id)->first();

    expect((int) $payslip->days_absent)->toBe(2)
        ->and((int) $payslip->days_worked)->toBe(24); // 26 ouvrés − 2 absents

    // Déduction non payée = 260000 / 26 × 2 = 20 000.
    $deduction = PayslipLine::where('payslip_id', $payslip->id)->where('category', 'absence')->first();
    expect($deduction)->not->toBeNull()
        ->and((int) $deduction->amount)->toBe(20000)
        ->and((int) $payslip->net_salary)->toBe(240000); // 260000 − 20000
});

test('sans absence pointée, la paie n\'est pas pénalisée', function () {
    $e = Employee::factory()->create(['status' => 'Actif', 'salary' => 260000]);

    EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $e->id,
        'attendance_date' => '2026-06-10', 'status' => 'present',
    ]);

    (new PayrollService())->generatePayroll($this->period);

    $payslip = Payslip::where('employee_id', $e->id)->where('payroll_period_id', $this->period->id)->first();

    expect((int) $payslip->days_absent)->toBe(0)
        ->and((int) $payslip->net_salary)->toBe(260000)
        ->and(PayslipLine::where('payslip_id', $payslip->id)->where('category', 'absence')->exists())->toBeFalse();
});
