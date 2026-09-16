<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN JOURNALIER ÉTAIT PAYÉ COMME UN PERMANENT.
 *
 * La paie présume les jours non pointés TRAVAILLÉS — « bénéfice du doute », dit
 * son commentaire — et ne compte comme absence que ce qui est pointé « absent » :
 *
 *     $daysWorked = max(0, $contract['working_days'] - $daysLeave - $daysAbsent);
 *
 * C'est la bonne règle pour un CDI ou un CDD : le salarié est censé être là, et
 * c'est l'ÉCART qu'on déclare. Elle est fausse pour un JOURNALIER, dont la
 * journée se constate : il n'est pas attendu, il vient.
 *
 * Or `contract_type` — qui existe depuis l'origine, avec les valeurs
 * « CDI », « CDD » et « Journalier » — n'est lu NULLE PART par la paie ni par le
 * pointage. Un journalier était donc traité exactement comme un permanent.
 *
 * ─── MESURÉ ───
 *
 * Juin 2026, 26 jours ouvrés. Un journalier à 1 300 000 GNF de référence
 * mensuelle, venu CINQ jours, pointés « présent ». Rien d'autre n'est saisi :
 *
 *   • avant  : 26 jours travaillés, 1 300 000 GNF versés ;
 *   • après  : 5 jours travaillés, 250 000 GNF versés (1 300 000 ÷ 26 × 5).
 *
 * Vingt et un jours payés à quelqu'un qui n'est pas venu — et aucune trace, la
 * fiche annonçant « 26 jours travaillés ».
 *
 * ─── LA RÈGLE POSÉE, ET SA SYMÉTRIE ───
 *
 * Deux régimes, commandés par le type de contrat :
 *
 *   • CDI / CDD — présomption de PRÉSENCE. On déclare les écarts : absences,
 *     congés. Rien ne change pour eux.
 *   • JOURNALIER — présomption d'ABSENCE. Seule une journée CONSTATÉE
 *     (« présent » ou « retard ») est due.
 *
 * C'est la même règle vue des deux côtés : on enregistre ce qui s'écarte de
 * l'ordinaire, et l'ordinaire n'est pas le même selon le contrat.
 *
 * ─── CE QU'ON NE DÉCIDE PAS ───
 *
 * Le champ `salary` reste lu comme une RÉFÉRENCE MENSUELLE, prorata des jours
 * ouvrés — c'est ainsi que tout le reste de la paie le traite déjà (prorata
 * d'entrée, retenue d'absence). Si vous rémunérez vos journaliers à un taux
 * JOURNALIER fixe, c'est un autre calcul, et il vous appartient de le dire.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    // Juin 2026 : 30 j − 4 dimanches = 26 jours ouvrés.
    $this->periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);
});

/** Un agent sous ce type de contrat, sous contrat sur toute la période. */
function agentSousContrat(string $typeDeContrat, int $salaire = 1_300_000): Employee
{
    return Employee::factory()->create([
        'status'            => 'Actif',
        'contract_type'     => $typeDeContrat,
        'salary'            => $salaire,
        'hire_date'         => '2024-01-15',
        'contract_end_date' => null,
    ]);
}

/** Pointe N jours OUVRÉS consécutifs depuis le lundi 1er juin 2026. */
function pointerLeRegime(int $employeeId, int $farmId, array $statuts): void
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

/** Le bulletin de la période pour cet agent. */
function bulletinDuRegime(int $employeeId, int $periodeId): ?Payslip
{
    return Payslip::where('employee_id', $employeeId)
        ->where('payroll_period_id', $periodeId)->first();
}

test('un journalier venu cinq jours est payé cinq jours', function () {
    /*
     * LE défaut : 21 jours payés à quelqu'un qui n'est pas venu, et la fiche
     * annonçant « 26 jours travaillés ».
     */
    $journalier = agentSousContrat('Journalier');
    pointerLeRegime($journalier->id, $this->farm->id, array_fill(0, 5, 'present'));

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDuRegime($journalier->id, $this->periode->id);

    expect((int) $bulletin->days_worked)->toBe(5)
        ->and((int) $bulletin->net_salary)->toBe(250_000);   // 1 300 000 ÷ 26 × 5
});

test('la retenue porte un motif lisible', function () {
    /*
     * Un net amputé sans ligne pour l'expliquer est incontrôlable. Le journalier
     * doit lire sur sa fiche pourquoi il touche ce montant.
     */
    $journalier = agentSousContrat('Journalier');
    pointerLeRegime($journalier->id, $this->farm->id, array_fill(0, 5, 'present'));

    (new PayrollService())->generatePayroll($this->periode);

    $ligne = PayslipLine::where('payslip_id', bulletinDuRegime($journalier->id, $this->periode->id)->id)
        ->where('category', 'journees_non_travaillees')->first();

    expect($ligne)->not->toBeNull()
        ->and((int) $ligne->amount)->toBe(1_050_000);   // 21 jours non venus
});

test('un RETARD reste une journée travaillée — non-régression', function () {
    // La règle déjà posée par EmployeeAttendance::WORKED, qu'on ne touche pas.
    $journalier = agentSousContrat('Journalier');
    pointerLeRegime($journalier->id, $this->farm->id, ['present', 'retard', 'present']);

    (new PayrollService())->generatePayroll($this->periode);

    expect((int) bulletinDuRegime($journalier->id, $this->periode->id)->days_worked)->toBe(3);
});

test('un journalier jamais pointé ne touche rien', function () {
    /*
     * La borne du régime : sans journée constatée, rien n'est dû. C'est
     * exactement l'inverse de la présomption qui vaut pour un permanent.
     */
    $journalier = agentSousContrat('Journalier');

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDuRegime($journalier->id, $this->periode->id);

    expect((int) $bulletin->days_worked)->toBe(0)
        ->and((int) $bulletin->net_salary)->toBe(0);
});

test('un CDI garde la présomption de présence — non-régression', function () {
    /*
     * LA borne de l'autre côté : rien ne change pour un permanent, y compris
     * quand son mois n'est pas pointé. C'est le cas de très loin le plus courant.
     */
    $permanent = agentSousContrat('CDI', 2_600_000);
    pointerLeRegime($permanent->id, $this->farm->id, array_fill(0, 5, 'present'));

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDuRegime($permanent->id, $this->periode->id);

    expect((int) $bulletin->days_worked)->toBe(26)
        ->and((int) $bulletin->net_salary)->toBe(2_600_000);
});

test('un CDD aussi — non-régression', function () {
    // Un CDD est un salarié attendu : même régime qu'un CDI.
    $cdd = agentSousContrat('CDD', 2_000_000);

    (new PayrollService())->generatePayroll($this->periode);

    expect((int) bulletinDuRegime($cdd->id, $this->periode->id)->days_worked)->toBe(26);
});

test('l’ABSENCE pointée d’un permanent est toujours déduite — non-régression', function () {
    /*
     * On ajoute un régime, on ne retire pas l'autre : la retenue d'absence d'un
     * CDI doit continuer de fonctionner exactement comme avant.
     */
    $permanent = agentSousContrat('CDI', 2_600_000);
    pointerLeRegime($permanent->id, $this->farm->id, ['absent', 'absent']);

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDuRegime($permanent->id, $this->periode->id);

    expect((int) $bulletin->days_absent)->toBe(2)
        ->and((int) $bulletin->days_worked)->toBe(24)
        ->and((int) $bulletin->net_salary)->toBe(2_400_000);   // 2 600 000 − 200 000
});

test('le CONGÉ d’un journalier ne lui ouvre pas de journée due', function () {
    /*
     * Un journalier en congé n'est pas « attendu mais absent » : il n'a
     * simplement pas de journée constatée. Compter son congé comme dû lui
     * ouvrirait un droit que son contrat ne porte pas.
     */
    $journalier = agentSousContrat('Journalier');
    pointerLeRegime($journalier->id, $this->farm->id, ['present', 'conge', 'conge']);

    (new PayrollService())->generatePayroll($this->periode);

    expect((int) bulletinDuRegime($journalier->id, $this->periode->id)->days_worked)->toBe(1);
});
