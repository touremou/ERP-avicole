<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE RAPPORT DE PRÉSENCE IGNORAIT LE TYPE DE CONTRAT.
 *
 * La paie distingue désormais deux régimes : un CDI ou un CDD est PRÉSUMÉ
 * PRÉSENT les jours non pointés, un JOURNALIER ne l'est pas — sa journée se
 * constate. Le rapport de présence, lui, appliquait le régime permanent à tout
 * le monde : jours dus = jours ouvrés du mois, travaillés = dus − absences.
 *
 * Pour un journalier, cela produit un chiffre faux dans les deux sens, et c'est
 * le même écran que le bureau consulte avant de valider la paie.
 *
 * ─── MESURÉ ───
 *
 * Juin 2026, 26 jours ouvrés. Un journalier venu CINQ jours :
 *
 *   • le rapport annonçait 26 jours travaillés et 100 % ;
 *   • son bulletin, lui, porte 5 jours depuis la correction de la paie.
 *
 * Deux écrans du même mois, deux réponses — exactement le désaccord que cette
 * campagne corrige ailleurs, ici entre le rapport et la fiche de paie.
 *
 * ─── LA RÈGLE, ET CE QU'ELLE SIGNIFIE POUR UN JOURNALIER ───
 *
 * Le rapport lit le même régime que la paie (`Employee::isPresumedPresent()`) :
 *
 *   • PERMANENT — jours dus = jours ouvrés − congés ; travaillés = dus −
 *     absences. Rien ne change.
 *   • JOURNALIER — il n'a pas de journée DUE : il a des journées CONSTATÉES.
 *     Le total affiché est donc le nombre de jours qu'il a faits, et son taux
 *     n'a pas de dénominateur — un journalier ne peut pas « manquer » un jour
 *     qu'on ne lui devait pas.
 *
 * On affiche donc 100 % pour lui dès qu'il est venu, et le vrai renseignement
 * est le NOMBRE de journées, que la colonne porte déjà. Inventer un taux sur le
 * mois ferait passer pour absentéiste quelqu'un qu'on n'attendait pas.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

/** Un agent sous ce type de contrat. */
function agentDuRapport(string $typeDeContrat): Employee
{
    return Employee::factory()->create([
        'status'            => 'Actif',
        'contract_type'     => $typeDeContrat,
        'salary'            => 1_300_000,
        'hire_date'         => '2024-01-15',
        'contract_end_date' => null,
    ]);
}

/** Pointe N jours OUVRÉS consécutifs depuis le lundi 1er juin 2026. */
function pointerPourLeRapport(int $employeeId, int $farmId, array $statuts): void
{
    $jour = \Carbon\Carbon::parse('2026-06-01');

    foreach ($statuts as $statut) {
        while (PayrollService::isRestDay($jour)) {
            $jour->addDay();
        }

        EmployeeAttendance::create([
            'farm_id' => $farmId, 'employee_id' => $employeeId,
            'attendance_date' => $jour->toDateString(), 'status' => $statut,
        ]);

        $jour->addDay();
    }
}

/** La ligne du rapport de juin 2026 pour cet agent. */
function ligneDuRapport(object $test, int $employeeId): array
{
    $lignes = $test->get(route('attendance.report', ['from' => '2026-06-01', 'to' => '2026-06-30']))
        ->assertOk()
        ->viewData('rows');

    foreach ($lignes as $ligne) {
        if ($ligne['employee']->id === $employeeId) {
            return $ligne;
        }
    }

    return [];
}

test('un journalier venu cinq jours en affiche cinq, pas vingt-six', function () {
    /*
     * LE défaut : le rapport disait 26 quand le bulletin dit 5.
     */
    $journalier = agentDuRapport('Journalier');
    pointerPourLeRapport($journalier->id, $this->farm->id, array_fill(0, 5, 'present'));

    $ligne = ligneDuRapport($this, $journalier->id);

    expect($ligne['worked'])->toBe(5)
        ->and($ligne['total'])->toBe(5);
});

test('un journalier jamais venu n’affiche aucune journée', function () {
    // La borne du régime : rien de constaté, rien à afficher.
    $journalier = agentDuRapport('Journalier');

    $ligne = ligneDuRapport($this, $journalier->id);

    expect($ligne['worked'])->toBe(0)
        ->and($ligne['total'])->toBe(0);
});

test('un journalier n’est jamais absentéiste', function () {
    /*
     * Il n'a pas de journée DUE : on ne peut pas lui reprocher un jour qu'on ne
     * lui devait pas. Lui appliquer le dénominateur du mois le ferait passer
     * pour absent vingt et un jours sur vingt-six — un jugement que son contrat
     * ne permet pas de porter.
     */
    $journalier = agentDuRapport('Journalier');
    pointerPourLeRapport($journalier->id, $this->farm->id, array_fill(0, 5, 'present'));

    expect(ligneDuRapport($this, $journalier->id)['presence_rate'])->toBe(100.0);
});

test('un RETARD reste une journée faite — non-régression', function () {
    // La règle d'`EmployeeAttendance::WORKED`, commune aux deux régimes.
    $journalier = agentDuRapport('Journalier');
    pointerPourLeRapport($journalier->id, $this->farm->id, ['present', 'retard', 'present']);

    expect(ligneDuRapport($this, $journalier->id)['worked'])->toBe(3);
});

test('le décompte brut de ses statuts reste ce qui a été saisi — non-régression', function () {
    // On change la lecture, pas l'enregistrement.
    $journalier = agentDuRapport('Journalier');
    pointerPourLeRapport($journalier->id, $this->farm->id, ['present', 'present', 'absent']);

    expect(ligneDuRapport($this, $journalier->id)['counts'])->toBe([
        'present' => 2, 'retard' => 0, 'absent' => 1, 'conge' => 0,
    ]);
});

test('un CDI garde le régime du mois — non-régression', function () {
    /*
     * LA borne de l'autre côté, et le cas de très loin le plus courant : pour un
     * permanent, le dénominateur reste le mois et les jours non pointés sont
     * présumés travaillés.
     */
    $permanent = agentDuRapport('CDI');
    pointerPourLeRapport($permanent->id, $this->farm->id, array_fill(0, 10, 'present'));

    $ligne = ligneDuRapport($this, $permanent->id);

    expect($ligne['total'])->toBe(26)
        ->and($ligne['worked'])->toBe(26)
        ->and($ligne['presence_rate'])->toBe(100.0);
});

test('et ses absences déclarées le font toujours baisser — non-régression', function () {
    // La mesure qui donne son sens au rapport pour un permanent.
    $permanent = agentDuRapport('CDI');
    pointerPourLeRapport($permanent->id, $this->farm->id, ['absent', 'absent']);

    $ligne = ligneDuRapport($this, $permanent->id);

    expect($ligne['worked'])->toBe(24)
        ->and($ligne['presence_rate'])->toBe(92.3);
});

test('le rapport et le bulletin disent le même nombre de journées', function () {
    /*
     * L'enjeu, de bout en bout : c'est la CONTRADICTION entre les deux écrans qui
     * rendait le rapprochement impossible, pas l'un des deux chiffres pris seul.
     * Le bureau consulte ce rapport avant de valider la paie.
     */
    $journalier = agentDuRapport('Journalier');
    pointerPourLeRapport($journalier->id, $this->farm->id, array_fill(0, 5, 'present'));

    $periode = \App\Models\PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);

    (new PayrollService())->generatePayroll($periode);

    $bulletin = \App\Models\Payslip::where('employee_id', $journalier->id)
        ->where('payroll_period_id', $periode->id)->firstOrFail();

    expect(ligneDuRapport($this, $journalier->id)['worked'])->toBe((int) $bulletin->days_worked);
});
