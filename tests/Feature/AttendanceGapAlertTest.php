<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Farm;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use App\Services\NotificationHub;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * POINTAGE MANQUANT — transformer un angle mort en garde-fou.
 *
 * La paie présume TRAVAILLÉ tout jour non pointé : bénéfice du doute, choisi
 * pour ne pas sanctionner un pointage incomplet. Sa conséquence n'était dite
 * nulle part : une période sans aucune feuille de présence produit exactement la
 * même paie qu'un mois de présence parfaite. Une absence d'une semaine est donc
 * PAYÉE, et rien ne le signale.
 *
 * Recommandé au promoteur en réponse à « que recommandes-tu ? », de préférence à
 * la répartition du coût salarial entre sites : celle-ci suppose de savoir
 * combien de jours ont réellement été travaillés de chaque côté — donc du
 * pointage. Sans lui, elle ne serait qu'un prorata de calendrier déguisé en
 * mesure.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
    Setting::clearCache();
});

/** Un jour ouvré récent, en évitant le repos hebdomadaire de l'exploitation. */
function recentWorkingDay(int $back = 1): \Illuminate\Support\Carbon
{
    $day = today();

    do {
        $day = $day->copy()->subDay();
        if (! PayrollService::isRestDay($day)) {
            $back--;
        }
    } while ($back > 0);

    return $day;
}

test('un jour ouvré sans feuille de présence déclenche une alerte', function () {
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $hub = Mockery::mock(NotificationHub::class);
    $hub->shouldReceive('alertAttendanceMissing')->once()
        ->withArgs(fn ($farmName, $missing, $headcount) => ! empty($missing) && $headcount >= 1);

    $this->app->instance(NotificationHub::class, $hub);

    $this->artisan('hr:check-attendance', ['--days' => 1])->assertSuccessful();
});

test('une journée POINTÉE ne déclenche rien', function () {
    $employee = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'attendance_date' => recentWorkingDay()->toDateString(), 'status' => 'present',
    ]);

    $hub = Mockery::mock(NotificationHub::class);
    $hub->shouldNotReceive('alertAttendanceMissing');
    $this->app->instance(NotificationHub::class, $hub);

    $this->artisan('hr:check-attendance', ['--days' => 1])->assertSuccessful();
});

test('le jour de REPOS ne compte pas comme un oubli', function () {
    // Une alerte qui sonne chaque dimanche cesse d'être lue — et le jour où elle
    // compte, elle passe pour du bruit.
    Setting::updateOrCreate(
        ['group' => 'rh', 'key' => 'rest_day'],
        ['value' => 'aucun', 'type' => 'string']
    );
    Setting::clearCache();

    expect(PayrollService::isRestDay(today()->next(\Carbon\Carbon::SUNDAY)))->toBeFalse();

    Setting::updateOrCreate(
        ['group' => 'rh', 'key' => 'rest_day'],
        ['value' => 'dimanche', 'type' => 'string']
    );
    Setting::clearCache();

    expect(PayrollService::isRestDay(today()->next(\Carbon\Carbon::SUNDAY)))->toBeTrue()
        ->and(PayrollService::isRestDay(today()->next(\Carbon\Carbon::TUESDAY)))->toBeFalse();
});

test('la règle du jour de repos est déclarée UNE fois', function () {
    // La paie et le contrôle de pointage doivent s'accorder : deux copies
    // divergeraient, et l'alerte crierait un jour que la paie ne compte pas.
    $service = file_get_contents(app_path('Services/PayrollService.php'));
    $command = file_get_contents(app_path('Console/Commands/CheckAttendanceGaps.php'));

    expect($command)->toContain('PayrollService::isRestDay(')
        ->and($command)->not->toContain("setting('rh.rest_day'")
        // Et dans la paie, la règle n'existe qu'à un endroit.
        ->and(substr_count($service, "setting('rh.rest_day'"))->toBe(1);
});

test('un site SANS personnel affecté n’est pas alerté', function () {
    // Faux positif permanent, sinon : un site sans agent n'a rien à pointer.
    Farm::firstOrCreate(['code' => 'VIDE-1'], ['name' => 'Site vide', 'is_active' => true]);

    $hub = Mockery::mock(NotificationHub::class);
    $hub->shouldNotReceive('alertAttendanceMissing');
    $this->app->instance(NotificationHub::class, $hub);

    // Aucun employé nulle part : aucune alerte, sur aucun site.
    $this->artisan('hr:check-attendance', ['--days' => 2])->assertSuccessful();
});

test('l’alerte NOMME le site : sinon on ne sait pas à qui la demander', function () {
    Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $captured = null;
    $hub = Mockery::mock(NotificationHub::class);
    $hub->shouldReceive('alertAttendanceMissing')->once()
        ->andReturnUsing(function ($farmName) use (&$captured) { $captured = $farmName; });
    $this->app->instance(NotificationHub::class, $hub);

    $this->artisan('hr:check-attendance', ['--days' => 1])->assertSuccessful();

    expect($captured)->toBe($this->farm->name);
});

test('le message dit la CONSÉQUENCE, pas seulement le manque', function () {
    // « Pointage manquant » seul ne se hiérarchise pas contre le reste de la
    // journée. Ce qui décide d'agir, c'est que les jours non pointés sont payés.
    $hub = file_get_contents(app_path('Services/NotificationHub.php'));

    preg_match('/public function alertAttendanceMissing.*?\n    \}/s', $hub, $m);

    expect($m[0])->toContain('présume ces jours TRAVAILLÉS')
        ->and($m[0])->toContain('Une absence non pointée est payée')
        // Au-delà de deux jours, ce n'est plus un oubli mais une perte de
        // traçabilité : le rattrapage devient une reconstitution de mémoire.
        ->and($m[0])->toContain("'critique' : 'attention'");
});

test('générer une paie SANS aucun pointage est refusé la première fois', function () {
    Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000, 'hire_date' => '2025-01-01',
    ]);

    $period = PayrollPeriod::create([
        'year' => 2026, 'month' => 7, 'label' => 'Juillet 2026',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.generate', $period))
        ->assertRedirect()
        ->assertSessionHas('error');

    // Rien n'a été produit : le refus doit être RÉEL, pas un simple avertissement.
    expect(\App\Models\Payslip::where('payroll_period_id', $period->id)->count())->toBe(0)
        ->and($period->fresh()->status)->toBe('brouillon');
});

test('la génération passe sur CONFIRMATION explicite', function () {
    // Blocage DOUX : un blocage dur ferait saisir n'importe quoi pour débloquer
    // la paie, et le pointage deviendrait une formalité. On refuse une fois, en
    // disant quoi faire, puis on laisse la décision au promoteur.
    Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000, 'hire_date' => '2025-01-01',
    ]);

    $period = PayrollPeriod::create([
        'year' => 2026, 'month' => 7, 'label' => 'Juillet 2026',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.generate', $period), ['confirm_no_attendance' => 1])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(\App\Models\Payslip::where('payroll_period_id', $period->id)->count())->toBe(1);
});

test('avec un seul jour pointé, la génération passe sans confirmation', function () {
    // Le blocage vise l'absence TOTALE de mesure, pas un pointage incomplet :
    // sanctionner l'incomplet reviendrait à punir celui qui a commencé à pointer.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_100_000, 'hire_date' => '2025-01-01',
    ]);

    $period = PayrollPeriod::create([
        'year' => 2026, 'month' => 7, 'label' => 'Juillet 2026',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);

    EmployeeAttendance::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'attendance_date' => '2026-07-15', 'status' => 'present',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.generate', $period))
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('le bouton « générer sans pointage » n’apparaît qu’APRÈS le refus', function () {
    // Offert d'emblée, il deviendrait le chemin normal — et le garde-fou serait
    // contourné sans avoir jamais été lu.
    $period = PayrollPeriod::create([
        'year' => 2026, 'month' => 7, 'label' => 'Juillet 2026',
        'start_date' => '2026-07-01', 'end_date' => '2026-07-31', 'status' => 'brouillon',
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertDontSee(e(__('Générer sans pointage')), false);

    $this->actingAs($this->adminUser)
        ->withSession(['confirm_no_attendance_period' => $period->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertSee(e(__('Générer sans pointage')), false);
});

test('le contrôle est planifié le SOIR, pas le lendemain matin', function () {
    // Découvert à la paie, l'oubli n'a plus de valeur : on ne reconstitue pas un
    // mois de présence de mémoire. Le soir, la journée se rattrape encore.
    $schedule = file_get_contents(base_path('routes/console.php'));

    expect($schedule)->toMatch("/Schedule::command\('hr:check-attendance'\)->dailyAt\('1[6-9]:\d\d'\)/");
});
