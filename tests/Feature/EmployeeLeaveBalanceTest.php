<?php

use App\Actions\Employee\CreateEmployee;
use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(fn () => $this->setUpRbac());

function makeEmployeeData(): array
{
    return [
        'last_name'     => 'Bah',
        'first_name'    => 'Aïssatou',
        'gender'        => 'F',
        'phone'         => '628112233',
        'job_title'     => 'Vétérinaire',
        'department'    => 'Santé',
        'contract_type' => 'CDI',
        'hire_date'     => now()->toDateString(),
    ];
}

test('un nouvel employé reçoit la dotation de congés du paramètre rh.annual_leave_days', function () {
    Setting::set('rh.annual_leave_days', 28);

    $employee = app(CreateEmployee::class)->execute(makeEmployeeData(), null, null);

    expect((int) $employee->annual_leave_balance)->toBe(28);
});

test('une dotation explicite n\'est pas écrasée par le paramètre', function () {
    Setting::set('rh.annual_leave_days', 30);

    $employee = app(CreateEmployee::class)->execute(
        makeEmployeeData() + ['annual_leave_balance' => 12],
        null,
        null
    );

    expect((int) $employee->annual_leave_balance)->toBe(12);
});
