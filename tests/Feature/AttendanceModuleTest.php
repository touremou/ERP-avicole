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
        'farm_id' => $this->farm->id, 'employee_id' => $e->id, 'type' => 'conge_annuel',
        'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        'days_count' => 3, 'status' => 'approuve',
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('attendance.index'))
        ->assertOk()
        ->assertSee('congé validé');
});

test('le rapport calcule le taux de présence (jours ouvrés − absences / jours dus)', function () {
    /*
     * Ce test portait l'ancienne règle jusque dans son titre : « présents+retards
     * / POINTÉS ». Le dénominateur était le nombre de lignes saisies, si bien que
     * le taux mesurait le zèle de saisie — dix jours pointés sur vingt-six, tous
     * présents, sortaient à 100 %. Il est désormais le MOIS, comme en paie.
     *
     * L'intention du test ne change pas : le rapport calcule bien un taux, et il
     * baisse quand on déclare une absence. Seule la grandeur mesurée est la
     * bonne. Cf. PresenceRateHasTheMonthAsDenominatorTest pour le détail.
     *
     * Fenêtre FIXE : ancrée sur `now()`, elle contenait un nombre de jours
     * ouvrés variable selon le jour de la semaine où la CI tournait.
     */
    $e = Employee::factory()->create([
        'status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    // Lundi 1er au vendredi 5 juin 2026 : 5 jours ouvrés.
    $jours = ['2026-06-01' => 'present', '2026-06-02' => 'present',
              '2026-06-03' => 'retard',  '2026-06-04' => 'absent'];

    foreach ($jours as $date => $st) {
        EmployeeAttendance::create([
            'farm_id' => $this->farm->id, 'employee_id' => $e->id,
            'attendance_date' => $date, 'status' => $st,
        ]);
    }

    $resp = $this->actingAs($this->adminUser)->get(route('attendance.report', [
        'from' => '2026-06-01', 'to' => '2026-06-05',
    ]));
    $resp->assertOk();

    $row = collect($resp->viewData('rows'))->firstWhere(fn ($r) => $r['employee']->id === $e->id);

    // 5 jours ouvrés, 1 absence déclarée → 4 travaillés, 80 %.
    expect($row['total'])->toBe(5)
        ->and($row['worked'])->toBe(4)
        ->and($row['presence_rate'])->toBe(80.0);
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
