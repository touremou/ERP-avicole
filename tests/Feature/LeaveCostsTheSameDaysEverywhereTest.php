<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN CONGÉ COÛTAIT HUIT JOURS DE DROIT ET N'EN CONSOMMAIT QUE SEPT.
 *
 * « Combien de jours ce congé coûte-t-il » se répondait à deux endroits, dans
 * deux unités différentes.
 *
 * Le SOLDE, en jours CALENDAIRES (`PayrollController::storeLeave`) :
 *
 *     $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
 *     ...
 *     'days_count' => $days,
 *
 * puis, à l'approbation :
 *
 *     $leave->employee?->decrement('annual_leave_balance', $leave->days_count);
 *
 * La PAIE, en jours OUVRÉS (`PayrollService`), avec le commentaire qui explique
 * pourquoi — une correction déjà faite de ce côté-là :
 *
 *     « EN JOURS OUVRÉS — le dimanche n'est pas un jour de congé. […] Un congé
 *       qui enjambe un jour de repos facturait ce repos au salarié, au taux
 *       d'une journée de travail. »
 *
 * Le raisonnement vaut mot pour mot pour le solde : un dimanche n'est pas un
 * jour de congé annuel, on ne le prélève donc pas du droit à congé.
 *
 * ─── MESURÉ ───
 *
 * Un congé annuel du lundi 8 au lundi 15 juin 2026 : 8 jours calendaires,
 * 7 ouvrés (un dimanche enjambé).
 *
 *   • le solde passait de 30 à 22   (−8, calendaires) ;
 *   • le bulletin ne portait que 7 jours de congé (ouvrés).
 *
 * L'agent perd UN JOUR de droit à congé que la paie ne lui a jamais décompté.
 * Sur quatre congés dans l'année enjambant chacun un dimanche, quatre jours.
 * Et « Jours de congé utilisés » (somme des `days_count`) affichait 8 quand le
 * bulletin en montrait 7 — deux chiffres du même fait sur deux écrans.
 *
 * ─── CE QU'ON NE DÉCIDE PAS ───
 *
 * Rien ne vérifie le solde AVANT d'accorder un congé, et `decrement()` n'a pas
 * de plancher : accorder 40 jours à qui en a 30 réussit et affiche « −10 / 30 ».
 * Refuser ou non un congé au-delà du droit acquis — donc autoriser ou non
 * l'anticipation sur l'exercice suivant — est une décision de l'exploitant, pas
 * une règle à poser ici. Ce fichier ne la tranche pas ; un test constate le
 * comportement actuel pour qu'un changement soit un choix.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);   // rh.S : la saisie vaut approbation

    $this->agent = Employee::factory()->create([
        'status'               => 'Actif',
        'salary'               => 2_600_000,
        'hire_date'            => '2024-01-15',
        'contract_end_date'    => null,
        'annual_leave_balance' => 30,
    ]);
});

/** Saisit un congé par la vraie porte du bureau (rh.S ⇒ approuvé d'emblée). */
function saisirUnConge(object $test, int $employeeId, string $type, string $du, string $au): void
{
    $test->post(route('payroll.leaves.store'), [
        'employee_id' => $employeeId,
        'type'        => $type,
        'start_date'  => $du,
        'end_date'    => $au,
        'reason'      => 'Congé',
    ]);
}

/** Le bulletin de juin 2026 de cet agent — 30 j, 4 dimanches, 26 ouvrés. */
function bulletinDeJuin(int $farmId, int $employeeId): ?Payslip
{
    $periode = PayrollPeriod::create([
        'farm_id' => $farmId, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);

    (new PayrollService())->generatePayroll($periode);

    return Payslip::where('payroll_period_id', $periode->id)
        ->where('employee_id', $employeeId)->first();
}

test('le solde se décompte en jours OUVRÉS, comme la paie', function () {
    /*
     * LE défaut : lundi 8 → lundi 15 juin 2026, 8 calendaires, 7 ouvrés.
     * Le solde en retirait 8.
     */
    saisirUnConge($this, $this->agent->id, 'conge_annuel', '2026-06-08', '2026-06-15');

    expect((int) $this->agent->fresh()->annual_leave_balance)->toBe(23);   // 30 − 7
});

test('et les deux écrans annoncent le même nombre', function () {
    /*
     * `days_count` est ce qu'affichent la liste des congés, la fiche employé et
     * l'historique de paie ; le bulletin, lui, compte en ouvrés. Les deux doivent
     * dire la même chose du même congé.
     */
    saisirUnConge($this, $this->agent->id, 'conge_annuel', '2026-06-08', '2026-06-15');

    $conge = EmployeeLeave::where('employee_id', $this->agent->id)->firstOrFail();
    $bulletin = bulletinDeJuin($this->farm->id, $this->agent->id);

    expect((int) $conge->days_count)->toBe(7)
        ->and((int) $bulletin->days_leave)->toBe(7);
});

test('un congé sans aucun jour de repos ne change pas — non-régression', function () {
    /*
     * La borne : la correction ne touche QUE les congés qui enjambent un repos.
     * Du lundi 8 au vendredi 12 : 5 calendaires, 5 ouvrés, aucun écart.
     */
    saisirUnConge($this, $this->agent->id, 'conge_annuel', '2026-06-08', '2026-06-12');

    $conge = EmployeeLeave::where('employee_id', $this->agent->id)->firstOrFail();

    expect((int) $conge->days_count)->toBe(5)
        ->and((int) $this->agent->fresh()->annual_leave_balance)->toBe(25);
});

test('un congé d’UN SEUL jour de repos ne coûte rien au droit', function () {
    /*
     * Le cas limite : un congé posé sur le seul dimanche. Il ne consomme aucun
     * jour de droit — c'est déjà un jour non travaillé.
     */
    saisirUnConge($this, $this->agent->id, 'conge_annuel', '2026-06-14', '2026-06-14');  // dimanche

    $conge = EmployeeLeave::where('employee_id', $this->agent->id)->firstOrFail();

    expect((int) $conge->days_count)->toBe(0)
        ->and((int) $this->agent->fresh()->annual_leave_balance)->toBe(30);
});

test('le jour de repos suit le RÉGLAGE de l’exploitation', function () {
    /*
     * `rh.rest_day` est la déclaration unique du repos hebdomadaire, et le
     * décompte doit la suivre — sinon on recréerait un second endroit où « le
     * dimanche » serait écrit en dur.
     *
     * Exploitation ouverte 7 j/7 : plus aucun jour n'est du repos, les 8 jours
     * calendaires redeviennent 8 jours ouvrés.
     */
    \App\Models\Setting::updateOrCreate(
        ['group' => 'rh', 'key' => 'rest_day'],
        ['value' => 'aucun', 'type' => 'text', 'label' => 'Jour de repos'],
    );
    \App\Models\Setting::clearCache();

    saisirUnConge($this, $this->agent->id, 'conge_annuel', '2026-06-08', '2026-06-15');

    expect((int) EmployeeLeave::where('employee_id', $this->agent->id)->value('days_count'))->toBe(8)
        ->and((int) $this->agent->fresh()->annual_leave_balance)->toBe(22);
});

test('un SANS-SOLDE ne touche pas au droit à congé — non-régression', function () {
    // Seul le congé annuel se prélève du solde ; le reste ne l'a jamais fait.
    saisirUnConge($this, $this->agent->id, 'sans_solde', '2026-06-08', '2026-06-15');

    expect((int) $this->agent->fresh()->annual_leave_balance)->toBe(30);
});

test('le solde peut encore passer sous zéro — décision de l’exploitant', function () {
    /*
     * On NE tranche PAS ici : autoriser ou non l'anticipation sur l'exercice
     * suivant est une décision de l'exploitant. Ce test constate le comportement
     * actuel, pour qu'un changement soit un choix et non un effet de bord de la
     * correction d'unité.
     *
     * Du lundi 1er juin au vendredi 31 juillet 2026 : 44 jours ouvrés, pour un
     * droit de 30.
     */
    saisirUnConge($this, $this->agent->id, 'conge_annuel', '2026-06-01', '2026-07-31');

    expect((int) $this->agent->fresh()->annual_leave_balance)->toBeLessThan(0);
});
