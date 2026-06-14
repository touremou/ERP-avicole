<?php

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->period = PayrollPeriod::create([
        'farm_id'    => $this->farm->id,
        'label'      => 'Juin 2026',
        'year'       => 2026,
        'month'      => 6,
        'start_date' => '2026-06-01',
        'end_date'   => '2026-06-30',
        'status'     => 'brouillon',
    ]);

    $employee = Employee::factory()->create(['status' => 'Actif']);

    $this->payslip = Payslip::create([
        'payroll_period_id' => $this->period->id,
        'employee_id'       => $employee->id,
        'base_salary'       => 208000, // → taux horaire = 1000 GNF (208 h)
        'days_worked'       => 26,
        'net_salary'        => 208000,
        'payment_status'    => 'en_attente',
    ]);
});

test('les heures supplémentaires sont calculées au taux majoré paramétré', function () {
    Setting::set('rh.overtime_rate', 1.5);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.overtime', $this->payslip), ['hours' => 10])
        ->assertSessionHas('success');

    // 1000 GNF/h × 10 h × 1,5 = 15 000 GNF
    $line = $this->payslip->lines()->where('category', 'heures_sup')->first();
    expect($line)->not->toBeNull();
    expect((int) $line->amount)->toBe(15000);

    $payslip = $this->payslip->fresh();
    expect((float) $payslip->overtime_hours)->toBe(10.0);
    expect((int) $payslip->net_salary)->toBe(223000); // 208000 + 15000
});

test('un nouvel enregistrement d\'heures sup remplace le précédent (pas de cumul de lignes)', function () {
    Setting::set('rh.overtime_rate', 2.0);

    $this->actingAs($this->adminUser)->post(route('payroll.overtime', $this->payslip), ['hours' => 5]);
    $this->actingAs($this->adminUser)->post(route('payroll.overtime', $this->payslip), ['hours' => 8]);

    expect($this->payslip->lines()->where('category', 'heures_sup')->count())->toBe(1);
    // 1000 × 8 × 2 = 16 000
    expect((int) $this->payslip->lines()->where('category', 'heures_sup')->first()->amount)->toBe(16000);
});
