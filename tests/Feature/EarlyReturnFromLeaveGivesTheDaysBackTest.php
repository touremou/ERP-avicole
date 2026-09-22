<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RENTRER PLUS TÔT DE CONGÉ COÛTAIT LES JOURS QU'ON N'AVAIT PAS PRIS.
 *
 * `endLeave` — « l'agent est de retour » — posait `status = 'termine'` et n'en
 * tirait AUCUNE conséquence :
 *
 *     $leave->update(['status' => 'termine']);
 *     $leave->employee->update(['status' => 'Actif']);
 *
 * La date de fin, le décompte et le solde continuaient donc de décrire un congé
 * qui n'a pas eu lieu.
 *
 * ─── MESURÉ ───
 *
 * Un congé annuel approuvé du 8 au 22 juin 2026 : quinze jours calendaires,
 * TREIZE ouvrés (le décompte se fait en jours ouvrés, deux dimanches tombent
 * dans l'intervalle). Le solde passe de 30 à 17. L'agent est rappelé pour une
 * urgence et revient le 10, après DEUX jours ouvrés :
 *
 *   • son solde reste à 17 — les ONZE jours non pris sont PERDUS ;
 *   • `end_date` annonce toujours le 22, `days_count` toujours 13 ;
 *   • il ne peut pas REPOSER ces jours : `EmployeeLeave::overlapping()` compte
 *     « termine » parmi les statuts qui occupent le calendrier, et le congé
 *     occupe encore sa durée d'origine. Il est bloqué par son propre congé ;
 *   • la paie compte TREIZE jours de congé — `PayrollService` lit
 *     `['approuve', 'en_cours', 'termine']` — sur des jours qu'il a travaillés.
 *
 * Quatre conséquences, une seule cause : le retour n'était écrit nulle part.
 *
 * ─── ET UN CONTRÔLE QUI SAUTAIT ───
 *
 * `endLeave` n'avait aucune garde de statut. « Terminer » une demande JAMAIS
 * APPROUVÉE la faisait passer à « termine ».
 *
 * Or approuver exige `rh.S`, terminer se contente de `rh.M` — et la paie compte
 * « termine ». Un gestionnaire pouvait donc faire reconnaître treize jours de
 * congé sans l'approbation que le système présente comme LE point de contrôle,
 * et sans que rien ne soit prélevé du solde, puisque le décompte n'a lieu qu'à
 * l'approbation. Un congé gratuit, et non approuvé.
 *
 * ─── LA RÈGLE POSÉE ───
 *
 * On ne termine que ce qui est EN COURS, et on enregistre ce qui s'est
 * réellement passé : fin au dernier jour réellement absent, décompte recalculé
 * par la MÊME déclaration que la paie (`PayrollService::workingDaysBetween`),
 * et jours non pris RENDUS — symétrique exact d'`applyLeaveApproval`, qui ne
 * prélève que sur le congé annuel.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    // Lundi 8 juin 2026. Les dates sont figées : ce dossier parle de jours
    // ouvrés, et un test qui change de réponse selon le jour où il tourne ne
    // prouve rien (cf. la correction de LeaveWorkflowTest).
    $this->travelTo('2026-06-08 08:00:00');
});

/** Un permanent, avec son droit à congé annuel entier. */
function agentAvecSolde(int $farmId, int $solde = 30): Employee
{
    return Employee::factory()->create([
        'farm_id' => $farmId, 'status' => 'Actif', 'contract_type' => 'CDI',
        'salary' => 2_600_000, 'hire_date' => '2024-01-15', 'contract_end_date' => null,
        'annual_leave_balance' => $solde,
    ]);
}

/** Un congé de ce type, sur cette période, dans cet état. */
function congeDe(int $farmId, int $employeeId, string $debut, string $fin, string $statut = 'demande', string $type = 'conge_annuel'): EmployeeLeave
{
    return EmployeeLeave::create([
        'farm_id' => $farmId, 'employee_id' => $employeeId, 'type' => $type,
        'start_date' => $debut, 'end_date' => $fin,
        'days_count' => PayrollService::workingDaysBetween(
            \Carbon\Carbon::parse($debut), \Carbon\Carbon::parse($fin)
        ),
        'status' => $statut,
    ]);
}

test('les jours non pris reviennent au solde', function () {
    /*
     * LE défaut : onze jours de droit à congé perdus parce que l'agent a
     * accepté de rentrer travailler.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22');

    $this->post(route('payroll.leaves.approve', $conge));
    expect($agent->fresh()->annual_leave_balance)->toBe(17);   // 13 jours OUVRÉS prélevés

    // Rappelé : il revient le 10, après deux jours.
    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    expect($agent->fresh()->annual_leave_balance)->toBe(28);   // 30 − 2 réellement pris
});

test('le congé dit ce qui s’est réellement passé', function () {
    // Sans cela, le dossier de l'agent décrit une absence qui n'a pas eu lieu.
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22');

    $this->post(route('payroll.leaves.approve', $conge));
    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    $conge->refresh();

    expect($conge->status)->toBe('termine')
        ->and($conge->end_date->toDateString())->toBe('2026-06-09')   // dernier jour absent
        ->and((int) $conge->days_count)->toBe(2);
});

test('et il peut REPOSER les jours qu’il n’a pas pris', function () {
    /*
     * La conséquence la plus sournoise : `overlapping()` compte « termine »
     * parmi les statuts qui occupent le calendrier. Tant que le congé gardait sa
     * durée d'origine, l'agent était bloqué par son propre congé — et le message
     * d'erreur lui désignait une absence qu'il n'avait pas prise.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22');

    $this->post(route('payroll.leaves.approve', $conge));
    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    expect(EmployeeLeave::overlapping($agent->id, '2026-06-15', '2026-06-20'))->toBeNull();
});

test('la paie compte les jours réellement pris, pas ceux prévus', function () {
    /*
     * L'enjeu de bout en bout : `PayrollService` lit `['approuve', 'en_cours',
     * 'termine']` et somme le recoupement avec la période. Tant que la date de
     * fin ne bougeait pas, le bulletin portait treize jours de congé sur des
     * jours travaillés.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22');

    $this->post(route('payroll.leaves.approve', $conge));
    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    $periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);
    (new PayrollService())->generatePayroll($periode);

    $bulletin = Payslip::where('employee_id', $agent->id)->firstOrFail();

    expect((int) $bulletin->days_leave)->toBe(2)
        ->and((int) $bulletin->days_worked)->toBe(24);   // 26 ouvrés − 2 de congé
});

test('rentrer le jour même de son départ enregistre cette journée', function () {
    /*
     * LA borne basse, et elle doit rester prudente : on ne rend jamais plus que
     * ce qui n'a pas été pris. Sans elle, la date de fin tomberait AVANT la date
     * de début — un congé aux bornes inversées.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-12');

    $this->post(route('payroll.leaves.approve', $conge));
    $this->post(route('payroll.leaves.end', $conge));   // le 8, jour du départ

    $conge->refresh();

    expect($conge->end_date->toDateString())->toBe('2026-06-08')
        ->and((int) $conge->days_count)->toBe(1)
        ->and($agent->fresh()->annual_leave_balance)->toBe(29);
});

test('une demande NON APPROUVÉE ne peut pas être « terminée »', function () {
    /*
     * LE contrôle qui sautait. Approuver exige `rh.S` ; terminer se contente de
     * `rh.M` — et la paie compte « termine ». Sans cette garde, un gestionnaire
     * faisait reconnaître un congé sans l'approbation qui est LE point de
     * contrôle du dispositif.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22', 'demande');

    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge))->assertSessionHas('error');

    expect($conge->fresh()->status)->toBe('demande');
});

test('et la paie ne compte pas cette demande', function () {
    /*
     * La garde ne vaut que par ce qu'elle empêche en bout de chaîne : treize
     * jours de congé portés à un bulletin sans qu'aucun habilité ne les ait
     * approuvés.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22', 'demande');

    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    $periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);
    (new PayrollService())->generatePayroll($periode);

    expect((int) Payslip::where('employee_id', $agent->id)->firstOrFail()->days_leave)->toBe(0);
});

test('un congé REFUSÉ ne peut pas être ressuscité par un retour', function () {
    // La même garde, vue de l'autre côté : un refus ne se contourne pas non plus.
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22', 'refuse');

    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge))->assertSessionHas('error');

    expect($conge->fresh()->status)->toBe('refuse');
});

test('un congé PAS ENCORE COMMENCÉ ne se termine pas', function () {
    /*
     * Ce geste-là est une ANNULATION, pas un retour. Le clore enregistrerait un
     * jour d'absence que personne n'a pris, et rendrait un solde faux.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-15', '2026-06-19');

    $this->post(route('payroll.leaves.approve', $conge));
    $soldeApresApprobation = $agent->fresh()->annual_leave_balance;

    $this->post(route('payroll.leaves.end', $conge))->assertSessionHas('error');

    expect($conge->fresh()->status)->toBe('approuve')
        ->and($agent->fresh()->annual_leave_balance)->toBe($soldeApresApprobation);
});

test('terminer deux fois ne rend pas les jours deux fois', function () {
    /*
     * Un geste qui rend de l'argent — ou du droit — doit être joué une seule
     * fois. La garde de statut le verrouille : « termine » n'est plus « en
     * cours ».
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22');

    $this->post(route('payroll.leaves.approve', $conge));
    $this->travelTo('2026-06-10 08:00:00');

    $this->post(route('payroll.leaves.end', $conge));
    $this->post(route('payroll.leaves.end', $conge))->assertSessionHas('error');

    expect($agent->fresh()->annual_leave_balance)->toBe(28);
});

test('un congé SANS SOLDE ne rend rien au droit annuel — non-régression', function () {
    /*
     * `applyLeaveApproval` ne prélève que sur le congé annuel ; le retour ne doit
     * rendre que là. Rendre des jours pour un congé sans solde CRÉERAIT du droit
     * à congé à partir d'une absence non payée.
     */
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22', 'demande', 'sans_solde');

    $this->post(route('payroll.leaves.approve', $conge));
    expect($agent->fresh()->annual_leave_balance)->toBe(30);   // rien prélevé

    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    expect($agent->fresh()->annual_leave_balance)->toBe(30)    // rien rendu
        ->and((int) $conge->fresh()->days_count)->toBe(2);     // mais la durée est juste
});

test('l’agent repasse ACTIF — non-régression', function () {
    // Ce que la méthode faisait déjà, et qui doit continuer.
    $agent = agentAvecSolde($this->farm->id);
    $conge = congeDe($this->farm->id, $agent->id, '2026-06-08', '2026-06-22');

    $this->post(route('payroll.leaves.approve', $conge));
    expect($agent->fresh()->status)->toBe('Congé');

    $this->travelTo('2026-06-10 08:00:00');
    $this->post(route('payroll.leaves.end', $conge));

    expect($agent->fresh()->status)->toBe('Actif');
});
