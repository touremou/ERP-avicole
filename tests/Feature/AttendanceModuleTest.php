<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('on enregistre la présence du jour pour l\'équipe', function () {
    $e1 = Employee::factory()->create(['status' => 'Actif']);
    $e2 = Employee::factory()->create(['status' => 'Actif']);
    $today = now()->toDateString();

    $this->actingAs($this->adminUser)
        ->post(route('attendance.store'), [
            'date'   => $today,
            'status' => [$e1->id => 'present', $e2->id => 'absent'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeAttendance::where('employee_id', $e1->id)->whereDate('attendance_date', $today)->value('status'))->toBe('present')
        ->and(EmployeeAttendance::where('employee_id', $e2->id)->whereDate('attendance_date', $today)->value('status'))->toBe('absent');
});

test('repointer la même date met à jour (pas de doublon)', function () {
    $e = Employee::factory()->create(['status' => 'Actif']);
    $today = now()->toDateString();

    foreach (['present', 'retard'] as $st) {
        $this->actingAs($this->adminUser)->post(route('attendance.store'), [
            'date' => $today, 'status' => [$e->id => $st],
        ])->assertSessionHas('success');
    }

    expect(EmployeeAttendance::where('employee_id', $e->id)->count())->toBe(1)
        ->and(EmployeeAttendance::where('employee_id', $e->id)->value('status'))->toBe('retard');
});

test('la grille pré-remplit « congé » pour un employé en congé validé', function () {
    $e = Employee::factory()->create(['status' => 'Actif']);
    EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $e->id, 'type' => 'annuel',
        'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        'days_count' => 3, 'status' => 'approuve',
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('attendance.index'))
        ->assertOk()
        ->assertSee('congé validé');
});

test('le rapport calcule le taux de présence (présents+retards / pointés)', function () {
    $e = Employee::factory()->create(['status' => 'Actif']);
    $statuses = ['present', 'present', 'retard', 'absent']; // 3 travaillés / 4 = 75 %
    foreach ($statuses as $i => $st) {
        EmployeeAttendance::create([
            'farm_id' => $this->farm->id, 'employee_id' => $e->id,
            'attendance_date' => now()->subDays($i)->toDateString(), 'status' => $st,
        ]);
    }

    $resp = $this->actingAs($this->adminUser)->get(route('attendance.report', [
        'from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString(),
    ]));
    $resp->assertOk();

    $row = collect($resp->viewData('rows'))->firstWhere(fn ($r) => $r['employee']->id === $e->id);
    expect($row['worked'])->toBe(3)
        ->and($row['total'])->toBe(4)
        ->and($row['presence_rate'])->toBe(75.0);
});

test('le rapport s\'exporte en CSV (employé + taux)', function () {
    $e = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Aïssa', 'last_name' => 'Bah']);
    EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $e->id,
        'attendance_date' => now()->toDateString(), 'status' => 'present',
    ]);

    $resp = $this->actingAs($this->adminUser)->get(route('attendance.report.csv', [
        'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(),
    ]))->assertOk();

    expect($resp->headers->get('content-type'))->toContain('text/csv');
    expect($resp->streamedContent())->toContain('Aïssa Bah')->toContain('Taux présence');
});

test('le rapport s\'exporte en PDF', function () {
    Employee::factory()->create(['status' => 'Actif']);

    $resp = $this->actingAs($this->adminUser)->get(route('attendance.report.pdf'))->assertOk();
    expect($resp->headers->get('content-type'))->toContain('application/pdf');
});
