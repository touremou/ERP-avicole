<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * APPROUVER UN CONGÉ FAISAIT DISPARAÎTRE UN AGENT DE L'EFFECTIF.
 *
 * Le hub RH compte son effectif et sa masse salariale sur un seul statut :
 *
 *     'headcount' => Employee::where('status', 'Actif')->count(),
 *     'payroll'   => Employee::where('status', 'Actif')->sum('salary'),
 *
 * Or approuver un congé bascule le statut RH de l'agent en « Congé »
 * (`PayrollController::applyLeaveApproval`). C'est exactement le défaut corrigé
 * dans la génération de paie — la règle « qui est au personnel que l'on paie »
 * y a été posée une fois, `Employee::onPayroll()`, et ces deux vignettes ne la
 * lisent pas.
 *
 * ─── MESURÉ ───
 *
 * Deux agents à 2 600 000 et 1 400 000 GNF. On approuve un congé couvrant
 * aujourd'hui pour le premier :
 *
 *   • « Effectif actif » tombe de 2 à 1 ;
 *   • « Masse salariale » tombe de 4 000 000 à 1 400 000.
 *
 * Un agent en congé payé n'a pourtant pas quitté l'exploitation : il est absent
 * aujourd'hui, et son salaire sera versé. Le hub annonçait une masse salariale
 * amputée de 2 600 000 GNF que la paie, elle, allait bel et bien décaisser.
 *
 * Et la fenêtre ne se referme pas toute seule : rien ne remet le statut à
 * « Actif » — `endLeave` est un bouton que quelqu'un doit cliquer, et aucune
 * commande planifiée ne clôt les congés échus. Un agent rentré depuis trois
 * semaines pour qui personne n'a cliqué manquait encore à l'effectif.
 *
 * ─── CE QU'ON NE CHANGE PAS ───
 *
 * La « masse salariale » du hub somme les SALAIRES CONTRACTUELS, quand la
 * période de paie somme les nets réellement calculés (prorata d'entrée,
 * retenues d'absence, primes, heures supplémentaires). Ce sont deux questions
 * différentes — « ce que l'exploitation s'engage à verser chaque mois » et « ce
 * qu'elle a versé en juin » — et la première est un indicateur légitime. On
 * corrige la POPULATION, pas la formule.
 *
 * « Suspendu » et « Parti » restent hors compte, comme pour la paie : ce sont
 * des situations d'emploi, et leur traitement appartient à l'exploitant.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->titulaire = Employee::factory()->create([
        'status' => 'Actif', 'salary' => 2_600_000,
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    $this->collegue = Employee::factory()->create([
        'status' => 'Actif', 'salary' => 1_400_000,
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);
});

/** Les indicateurs du hub RH tels que l'écran les calcule. */
function indicateursDuHub(object $test): array
{
    return $test->get(route('rh.index'))->assertOk()->viewData('kpis');
}

/** Met l'agent en congé approuvé couvrant aujourd'hui, par la vraie porte. */
function mettreEnCongeAujourdHui(object $test, Employee $agent): void
{
    $test->post(route('payroll.leaves.store'), [
        'employee_id' => $agent->id,
        'type'        => 'conge_annuel',
        'start_date'  => today()->toDateString(),
        'end_date'    => today()->addDay()->toDateString(),
        'reason'      => 'Congé',
    ]);
}

test('un agent en congé reste dans l’effectif', function () {
    /*
     * LE défaut : approuver un congé retirait l'agent du compte.
     */
    expect((int) indicateursDuHub($this)['headcount'])->toBe(2);

    mettreEnCongeAujourdHui($this, $this->titulaire);

    expect($this->titulaire->fresh()->status)->toBe('Congé')          // le décor, vérifié
        ->and((int) indicateursDuHub($this)['headcount'])->toBe(2);
});

test('et dans la masse salariale, qui sera bel et bien décaissée', function () {
    // L'autre vignette, sur la même population — et 2 600 000 GNF d'écart.
    expect((float) indicateursDuHub($this)['payroll'])->toBe(4_000_000.0);

    mettreEnCongeAujourdHui($this, $this->titulaire);

    expect((float) indicateursDuHub($this)['payroll'])->toBe(4_000_000.0);
});

test('un agent rentré mais jamais repassé « Actif » compte toujours', function () {
    /*
     * La fenêtre réelle : rien ne remet le statut tout seul. L'agent est rentré,
     * personne n'a cliqué « Retour », et il manquait encore à l'effectif.
     */
    $this->titulaire->update(['status' => 'Congé']);

    EmployeeLeave::create([
        'farm_id' => $this->farm->id, 'employee_id' => $this->titulaire->id,
        'type' => 'conge_annuel',
        'start_date' => today()->subDays(30)->toDateString(),
        'end_date'   => today()->subDays(25)->toDateString(),
        'days_count' => 5, 'status' => 'approuve',
    ]);

    expect((int) indicateursDuHub($this)['headcount'])->toBe(2);
});

test('un agent PARTI ne compte pas — non-régression', function () {
    // Une situation d'emploi close : il a quitté l'exploitation.
    $this->titulaire->update(['status' => 'Parti']);

    $kpis = indicateursDuHub($this);

    expect((int) $kpis['headcount'])->toBe(1)
        ->and((float) $kpis['payroll'])->toBe(1_400_000.0);
});

test('un agent SUSPENDU ne compte pas — décision de l’exploitant', function () {
    /*
     * Même arbitrage que pour la paie : savoir si une suspension disciplinaire
     * est payée appartient à l'exploitant. Le comportement reste celui d'avant,
     * et ce test est là pour qu'un changement soit un choix.
     */
    $this->titulaire->update(['status' => 'Suspendu']);

    $kpis = indicateursDuHub($this);

    expect((int) $kpis['headcount'])->toBe(1)
        ->and((float) $kpis['payroll'])->toBe(1_400_000.0);
});

test('la présence du jour ne compte pas l’absent — non-régression', function () {
    /*
     * LA borne : être à l'effectif n'est pas être présent. Un agent en congé n'a
     * aucun pointage, et la vignette « présents » doit continuer de le dire.
     */
    mettreEnCongeAujourdHui($this, $this->titulaire);

    expect((int) indicateursDuHub($this)['present'])->toBe(0);
});

test('l’effectif suit le retour de congé — bout en bout', function () {
    // Par les vraies portes : le compte ne doit bouger dans aucun sens.
    mettreEnCongeAujourdHui($this, $this->titulaire);

    expect((int) indicateursDuHub($this)['headcount'])->toBe(2);

    $conge = EmployeeLeave::where('employee_id', $this->titulaire->id)->firstOrFail();
    $this->post(route('payroll.leaves.end', $conge->id));

    expect($this->titulaire->fresh()->status)->toBe('Actif')
        ->and((int) indicateursDuHub($this)['headcount'])->toBe(2);
});
