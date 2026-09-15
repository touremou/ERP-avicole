<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE TAUX DE PRÉSENCE RÉCOMPENSAIT CELUI QUI NE POINTE PAS.
 *
 * `AttendanceController::buildReport` divisait par le nombre de LIGNES SAISIES :
 *
 *     $total  = array_sum($counts);              // present + retard + absent + congé
 *     $worked = $counts['present'] + $counts['retard'];
 *     'presence_rate' => $total > 0 ? round($worked / $total * 100, 1) : 0.0,
 *
 * Le dénominateur n'était donc pas le mois, mais ce que quelqu'un avait bien
 * voulu enregistrer. Conséquence, mesurée sur juin 2026 (26 jours ouvrés) :
 *
 *   • site A, 10 jours pointés, tous « présent »      → 100 %
 *   • site B, 26 jours pointés, 24 présents, 2 absents →  92,3 %
 *
 * Le site qui pointe consciencieusement et déclare ses absences affiche un taux
 * PLUS BAS que celui qui ne pointe qu'une fois sur trois. L'indicateur ne peut
 * pas baisser en oubliant de pointer — il ne peut que monter. Et c'est ce chiffre
 * que le bureau regarde avant de valider une paie.
 *
 * ─── LE MOIS EST DÉJÀ DÉCLARÉ AILLEURS ───
 *
 * La paie ne se pose pas la question : elle compte `jours ouvrés du contrat −
 * congés − absences`, et les jours non pointés sont présumés travaillés. Le
 * dénominateur qu'il faut est donc celui qu'elle emploie déjà —
 * `PayrollService::workingDaysBetween()`, bâti sur le réglage `rh.rest_day`.
 *
 * Le taux affiché passe donc de « part des jours saisis qui sont travaillés » à
 * « part des jours OUVRÉS de la période qui sont travaillés », les jours non
 * pointés étant présumés travaillés comme la paie le fait. Les deux écrans
 * répondent enfin à la même question.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->agent = Employee::factory()->create([
        'status' => 'Actif', 'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);
});

/** Pointe l'agent sur N jours OUVRÉS consécutifs à partir du lundi 1er juin 2026. */
function pointerDesJoursOuvres(int $employeeId, int $farmId, array $statuts): void
{
    $jour = \Carbon\Carbon::parse('2026-06-01');   // un lundi

    foreach ($statuts as $statut) {
        while (\App\Services\PayrollService::isRestDay($jour)) {
            $jour->addDay();
        }

        EmployeeAttendance::create([
            'farm_id'         => $farmId,
            'employee_id'     => $employeeId,
            'attendance_date' => $jour->toDateString(),
            'status'          => $statut,
        ]);

        $jour->addDay();
    }
}

/** Le rapport de présence de juin 2026 — 30 jours, 4 dimanches, 26 ouvrés. */
function rapportDeJuin(object $test, int $employeeId): array
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

test('dix jours pointés sur vingt-six n’affichent pas 100 %', function () {
    /*
     * LE défaut : un mois pointé une fois sur trois, tous « présent », sortait à
     * 100 % — un taux qui ne peut pas baisser en oubliant de pointer.
     */
    pointerDesJoursOuvres($this->agent->id, $this->farm->id, array_fill(0, 10, 'present'));

    $ligne = rapportDeJuin($this, $this->agent->id);

    expect($ligne['total'])->toBe(26)                       // le MOIS, pas les lignes
        ->and($ligne['presence_rate'])->toBe(100.0);        // 26 ouvrés, 0 absence
});

test('le site qui DÉCLARE ses absences n’est plus le moins bien noté', function () {
    /*
     * L'enjeu, mesuré : avant, 24 présents + 2 absents sur 26 jours pointés
     * donnaient 92,3 %, contre 100 % au site qui n'en pointait que dix. Le
     * consciencieux passait pour le mauvais.
     */
    $statuts = array_merge(array_fill(0, 24, 'present'), ['absent', 'absent']);
    pointerDesJoursOuvres($this->agent->id, $this->farm->id, $statuts);

    $ligne = rapportDeJuin($this, $this->agent->id);

    // 26 ouvrés − 2 absences = 24 travaillés.
    expect($ligne['total'])->toBe(26)
        ->and($ligne['worked'])->toBe(24)
        ->and($ligne['presence_rate'])->toBe(92.3);
});

test('deux absences déclarées pèsent autant, pointées ou non', function () {
    /*
     * LA borne qui donne son sens au changement : le taux doit dépendre des
     * ABSENCES, et d'elles seules — pas du zèle de saisie. Deux absences
     * déclarées sur un mois non pointé par ailleurs donnent le même taux que
     * deux absences dans un mois entièrement pointé.
     */
    pointerDesJoursOuvres($this->agent->id, $this->farm->id, ['absent', 'absent']);

    $ligne = rapportDeJuin($this, $this->agent->id);

    expect($ligne['worked'])->toBe(24)
        ->and($ligne['presence_rate'])->toBe(92.3);
});

test('un mois sans aucun pointage est présumé travaillé, comme en paie', function () {
    /*
     * C'est la règle que la paie applique déjà — « les jours non pointés sont
     * présumés travaillés (bénéfice du doute) ». Le rapport disait l'inverse :
     * 0 %, c'est-à-dire personne au travail de tout le mois.
     *
     * Le bureau est averti ailleurs qu'aucun pointage n'a été saisi
     * (`PayrollService::pointedDaysIn`) : ce n'est pas au taux de le crier en
     * annonçant un chiffre faux.
     */
    $ligne = rapportDeJuin($this, $this->agent->id);

    expect($ligne['total'])->toBe(26)
        ->and($ligne['worked'])->toBe(26)
        ->and($ligne['presence_rate'])->toBe(100.0);
});

test('les CONGÉS ne comptent pas comme des absences', function () {
    /*
     * Un congé validé n'est pas un manquement. La paie le distingue déjà
     * (days_leave contre days_absent) ; le taux doit le distinguer aussi, sinon
     * un agent en congé annuel ferait chuter l'indicateur de son site.
     */
    $statuts = array_merge(array_fill(0, 5, 'conge'), array_fill(0, 21, 'present'));
    pointerDesJoursOuvres($this->agent->id, $this->farm->id, $statuts);

    $ligne = rapportDeJuin($this, $this->agent->id);

    expect($ligne['counts']['conge'])->toBe(5)
        ->and($ligne['worked'])->toBe(21)
        ->and($ligne['presence_rate'])->toBe(100.0);   // 21 travaillés sur 21 dus
});

test('un RETARD reste une journée travaillée — non-régression', function () {
    // La règle déjà posée par EmployeeAttendance::WORKED, qu'on ne touche pas.
    $statuts = array_merge(array_fill(0, 3, 'retard'), array_fill(0, 23, 'present'));
    pointerDesJoursOuvres($this->agent->id, $this->farm->id, $statuts);

    $ligne = rapportDeJuin($this, $this->agent->id);

    expect($ligne['counts']['retard'])->toBe(3)
        ->and($ligne['worked'])->toBe(26)
        ->and($ligne['presence_rate'])->toBe(100.0);
});

test('le décompte brut de chaque statut ne bouge pas — non-régression', function () {
    /*
     * On change le DÉNOMINATEUR, pas le comptage : les colonnes « présent »,
     * « absent », « congé » du tableau restent ce qui a été saisi.
     */
    $statuts = array_merge(array_fill(0, 8, 'present'), ['absent'], ['conge']);
    pointerDesJoursOuvres($this->agent->id, $this->farm->id, $statuts);

    $ligne = rapportDeJuin($this, $this->agent->id);

    expect($ligne['counts'])->toBe([
        'present' => 8, 'retard' => 0, 'absent' => 1, 'conge' => 1,
    ]);
});
