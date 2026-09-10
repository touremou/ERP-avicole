<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\PayrollService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN AGENT EN CONGÉ PAYÉ NE RECEVAIT AUCUN BULLETIN.
 *
 * Approuver un congé change le STATUT RH de l'agent
 * (`PayrollController::applyLeaveApproval`) :
 *
 *     if ($leave->isActiveOn(now())) {
 *         $leave->employee?->update(['status' => 'Congé']);
 *     }
 *
 * Et la génération de paie ne connaît qu'un seul statut
 * (`PayrollService::generatePayroll`) :
 *
 *     $employees = Employee::where('status', 'Actif')->get();
 *
 * Un agent en congé annuel APPROUVÉ le jour de la génération disparaît donc
 * entièrement de la paie : ni salaire, ni ligne de congé, ni comptage dans les
 * indicateurs de la période. Le message de succès annonce « N fiches générées »
 * sans mentionner l'absent, et rien ne le signale — le compteur
 * `out_of_contract` ne se déclenche que hors contrat.
 *
 * ─── LE SERVICE SE CONTREDIT LUI-MÊME ───
 *
 * `generatePayroll` consacre soixante-dix lignes à décompter les congés d'un
 * agent — `['approuve', 'en_cours', 'termine']`, chevauchement avec la fenêtre
 * contractuelle, jours ouvrés, congé payé contre sans-solde. Ce code est
 * INATTEIGNABLE pour quiconque est en congé le jour de la génération : il ne
 * sera jamais dans la boucle.
 *
 * ─── ET LA FENÊTRE EST BIEN PLUS LARGE QU'UN MOIS ───
 *
 * Rien ne remet le statut à « Actif » automatiquement : `endLeave` est un bouton
 * « Retour » que quelqu'un doit cliquer, et aucune commande planifiée ne clôt les
 * congés échus. Un agent rentré de congé il y a trois semaines, mais pour qui
 * personne n'a cliqué, est TOUJOURS « Congé » — et reste hors paie le mois
 * suivant, puis celui d'après.
 *
 * ─── CE QU'ON NE DÉCIDE PAS ───
 *
 * Un congé payé n'est pas un changement de situation d'emploi : c'est un état de
 * présence temporaire. « Suspendu » et « Parti », eux, sont des situations
 * d'emploi, et savoir si une suspension disciplinaire est payée est une décision
 * de l'exploitant, pas la nôtre. Ces deux statuts restent donc EXACTEMENT comme
 * avant — deux tests le tiennent.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Juin 2026 : 30 j − 4 dimanches = 26 jours ouvrés.
    $this->periode = PayrollPeriod::create([
        'farm_id' => $this->farm->id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);
});

/** Un agent salarié, sous contrat sur toute la période. */
function agentSalarie(string $statut, int $salaire = 2_600_000): Employee
{
    return Employee::factory()->create([
        'status'            => $statut,
        'salary'            => $salaire,
        'hire_date'         => '2024-01-15',
        'contract_end_date' => null,
    ]);
}

/** Un congé annuel approuvé sur cette fenêtre. */
function congeAnnuelApprouve(int $employeeId, int $farmId, string $du, string $au): EmployeeLeave
{
    return EmployeeLeave::create([
        'farm_id'    => $farmId,
        'employee_id' => $employeeId,
        'type'       => 'conge_annuel',
        'start_date' => $du,
        'end_date'   => $au,
        'days_count' => \Carbon\Carbon::parse($du)->diffInDays(\Carbon\Carbon::parse($au)) + 1,
        'status'     => 'approuve',
        'reason'     => 'Congé annuel',
    ]);
}

/** Le bulletin de cet agent sur la période. */
function bulletinDe(int $employeeId, int $periodeId): ?Payslip
{
    return Payslip::where('employee_id', $employeeId)
        ->where('payroll_period_id', $periodeId)
        ->first();
}

test('un agent en congé payé reçoit son bulletin', function () {
    /*
     * LE défaut : 2 600 000 GNF non versés, et personne pour s'en apercevoir.
     */
    $agent = agentSalarie('Congé');
    congeAnnuelApprouve($agent->id, $this->farm->id, '2026-06-08', '2026-06-12');

    (new PayrollService())->generatePayroll($this->periode);

    expect(bulletinDe($agent->id, $this->periode->id))->not->toBeNull();
});

test('et son bulletin porte bien ses jours de congé', function () {
    /*
     * Les soixante-dix lignes de décompte des congés doivent réellement tourner :
     * un bulletin produit mais aveugle au congé ne vaudrait guère mieux.
     *
     * Du lundi 8 au vendredi 12 juin 2026 : 5 jours ouvrés.
     */
    $agent = agentSalarie('Congé');
    congeAnnuelApprouve($agent->id, $this->farm->id, '2026-06-08', '2026-06-12');

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDe($agent->id, $this->periode->id);

    expect((int) $bulletin->days_leave)->toBe(5)
        ->and((int) $bulletin->days_worked)->toBe(21)          // 26 ouvrés − 5 de congé
        ->and((int) $bulletin->net_salary)->toBe(2_600_000);   // le congé annuel est PAYÉ
});

test('un agent rentré de congé, mais jamais repassé « Actif », est payé aussi', function () {
    /*
     * La fenêtre réelle du défaut : rien ne remet le statut à « Actif » tout seul.
     * Le congé est terminé depuis des semaines, personne n'a cliqué « Retour »,
     * et l'agent sortait de la paie mois après mois.
     */
    $agent = agentSalarie('Congé');
    congeAnnuelApprouve($agent->id, $this->farm->id, '2026-03-02', '2026-03-06');

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDe($agent->id, $this->periode->id);

    expect($bulletin)->not->toBeNull()
        ->and((int) $bulletin->days_leave)->toBe(0)            // le congé est hors période
        ->and((int) $bulletin->net_salary)->toBe(2_600_000);
});

test('un agent ACTIF garde exactement le même bulletin — non-régression', function () {
    // Le cas de très loin le plus courant : rien ne doit bouger pour lui.
    $agent = agentSalarie('Actif');

    (new PayrollService())->generatePayroll($this->periode);

    $bulletin = bulletinDe($agent->id, $this->periode->id);

    expect($bulletin)->not->toBeNull()
        ->and((int) $bulletin->days_worked)->toBe(26)
        ->and((int) $bulletin->net_salary)->toBe(2_600_000);
});

test('un agent PARTI ne reçoit toujours rien — non-régression', function () {
    // Une situation d'emploi close : la paie n'a rien à produire.
    $agent = agentSalarie('Parti');

    (new PayrollService())->generatePayroll($this->periode);

    expect(bulletinDe($agent->id, $this->periode->id))->toBeNull();
});

test('un agent SUSPENDU ne reçoit toujours rien — décision de l’exploitant', function () {
    /*
     * On ne tranche PAS ici si une suspension disciplinaire est payée : c'est une
     * décision de l'exploitant. Le comportement reste donc strictement celui
     * d'avant, et ce test est là pour qu'un changement soit un choix, pas un
     * effet de bord.
     */
    $agent = agentSalarie('Suspendu');

    (new PayrollService())->generatePayroll($this->periode);

    expect(bulletinDe($agent->id, $this->periode->id))->toBeNull();
});

test('le compte rendu de génération n’oublie personne', function () {
    /*
     * Le nombre annoncé au bureau doit couvrir tout le monde : c'est le seul
     * signal qui aurait pu révéler l'absent.
     */
    agentSalarie('Actif');
    agentSalarie('Congé');

    $bilan = (new PayrollService())->generatePayroll($this->periode);

    expect($bilan['created'])->toBe(2)
        ->and(Payslip::where('payroll_period_id', $this->periode->id)->count())->toBe(2);
});
