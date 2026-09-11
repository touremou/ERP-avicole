<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Farm;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LA MÊME PAGE AFFICHAIT « 8 JOURS DE CONGÉ » ET « AUCUN CONGÉ ».
 *
 * Un congé est classé au DOSSIER de l'agent, donc sur son site d'ORIGINE —
 * décision métier prise avec le promoteur, et `storeLeave` l'applique :
 * `'farm_id' => $employee->farm_id`. C'est ce qui permet à la paie, qui le paie
 * depuis ce site, de compter ses jours.
 *
 * `Employee::leaves()` en tire la conséquence, et le dit :
 *
 *     return $this->hasMany(EmployeeLeave::class)
 *         ->withoutGlobalScope(\App\Scopes\FarmScope::class);
 *
 * La paie lit par cette relation. Trois autres lecteurs, non :
 *
 *   • `PayrollController::employeeHistory` — la liste des congés de la fiche ;
 *   • le même, pour le total « Jours de congé utilisés » ;
 *   • `employees/show.blade.php` — la carte « Congés & Absences ».
 *
 * Tous trois interrogent `EmployeeLeave::where('employee_id', …)` en direct,
 * donc sous le scope de ferme.
 *
 * ─── MESURÉ ───
 *
 * Un agent prêté, consulté depuis son SITE D'ACCUEIL — le seul endroit d'où on
 * le voit travailler :
 *
 *   • son bulletin porte `days_leave = 5` ;
 *   • « Jours de congé utilisés » annonce 0 ;
 *   • la liste des congés de la même page est vide.
 *
 * Deux blocs de la MÊME page se contredisent, et celui qui se tait est celui
 * qu'on consulte pour vérifier l'autre. Le bureau conclut que la paie a déduit
 * des congés qui n'existent pas.
 *
 * ─── CE QU'ON NE CHANGE PAS ───
 *
 * Ni le classement du congé (il reste au site d'origine — c'est la décision
 * métier), ni ce que voit l'écran de GESTION des congés, qui a déjà sa propre
 * règle de périmètre. On fait seulement lire à la fiche de l'agent la relation
 * que le modèle déclare pour elle.
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

/** Agent dont le dossier vit ailleurs, mis à disposition de cette ferme. */
function agentPreteAvecDossierAilleurs(Farm $accueil, User $memeRoleQue): Employee
{
    $origine = Farm::firstOrCreate(['code' => 'KER-900'], ['name' => 'Kérouané', 'is_active' => true]);

    $compte = User::factory()->create(['role_id' => $memeRoleQue->role_id]);
    DB::table('farm_user')->insert([
        'farm_id' => $accueil->id, 'user_id' => $compte->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $agent = Employee::factory()->create([
        'farm_id'           => $origine->id,
        'user_id'           => $compte->id,
        'status'            => 'Actif',
        'salary'            => 2_600_000,
        'hire_date'         => '2024-01-15',
        'contract_end_date' => null,
    ]);

    $agent->lendTo($accueil->id, today()->subMonth());

    return $agent;
}

/** Un congé classé au site d'ORIGINE, comme le fait `storeLeave`. */
function congeClasseALOrigine(Employee $agent, string $du, string $au, int $jours): EmployeeLeave
{
    return EmployeeLeave::create([
        'farm_id'     => $agent->farm_id,        // ← le site d'origine, pas celui d'accueil
        'employee_id' => $agent->id,
        'type'        => 'conge_annuel',
        'start_date'  => $du,
        'end_date'    => $au,
        'days_count'  => $jours,
        'status'      => 'approuve',
        'reason'      => 'Congé annuel',
    ]);
}

test('« Jours de congé utilisés » compte les congés de l’agent prêté', function () {
    /*
     * LE défaut : le total annonçait 0 sur la page même où le bulletin affiche
     * les jours déduits.
     */
    $agent = agentPreteAvecDossierAilleurs($this->farm, $this->adminUser);
    congeClasseALOrigine($agent, '2026-06-08', '2026-06-12', 5);

    $reponse = $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $agent->id))
        ->assertOk();

    expect((int) $reponse->viewData('totals')['leave_days_used'])->toBe(5);
});

test('et la liste des congés de la même page ne se tait plus', function () {
    // L'autre moitié du même défaut : le bloc consulté pour vérifier était vide.
    $agent = agentPreteAvecDossierAilleurs($this->farm, $this->adminUser);
    $conge = congeClasseALOrigine($agent, '2026-06-08', '2026-06-12', 5);

    $reponse = $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $agent->id))
        ->assertOk();

    expect($reponse->viewData('leaves')->pluck('id'))->toContain($conge->id);
});

test('la fiche employé montre le congé en cours de l’agent prêté', function () {
    /*
     * Le troisième lecteur : la carte « Congés & Absences » de la fiche, qui
     * refaisait sa propre requête sous le scope de ferme.
     */
    $agent = agentPreteAvecDossierAilleurs($this->farm, $this->adminUser);
    congeClasseALOrigine($agent, today()->toDateString(), today()->addDays(4)->toDateString(), 5);

    $this->actingAs($this->adminUser)
        ->get(route('employees.show', $agent->id))
        ->assertOk()
        ->assertSee('Congé annuel', false);
});

test('le bulletin et le total disent le même nombre', function () {
    /*
     * L'enjeu, mesuré de bout en bout : c'est la CONTRADICTION entre deux blocs
     * de la même page qui rendait la fiche inutilisable, pas l'un des deux
     * chiffres pris seul.
     *
     * Du lundi 8 au vendredi 12 juin 2026 : 5 jours ouvrés.
     */
    $agent = agentPreteAvecDossierAilleurs($this->farm, $this->adminUser);
    congeClasseALOrigine($agent, '2026-06-08', '2026-06-12', 5);

    $periode = PayrollPeriod::create([
        'farm_id' => $agent->farm_id, 'label' => 'Juin 2026', 'year' => 2026, 'month' => 6,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'brouillon',
    ]);

    /*
     * La paie se fait depuis le site qui PAIE — celui d'origine. Lancer la
     * génération en restant sur le site d'accueil ne produirait aucun bulletin
     * pour cet agent, et le test mesurerait alors ce décalage-là plutôt que le
     * défaut visé.
     */
    session(['current_farm_id' => $agent->farm_id]);
    (new PayrollService())->generatePayroll($periode);

    $bulletin = Payslip::withoutGlobalScopes()
        ->where('employee_id', $agent->id)->where('payroll_period_id', $periode->id)->first();

    // On revient consulter sa fiche depuis le site d'ACCUEIL : c'est là qu'on le
    // voit travailler, et c'est là que la contradiction se lisait.
    session(['current_farm_id' => $this->farm->id]);

    $reponse = $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $agent->id))->assertOk();

    expect((int) $bulletin->days_leave)->toBe(5)
        ->and((int) $reponse->viewData('totals')['leave_days_used'])->toBe((int) $bulletin->days_leave);
});

test('un agent NON prêté n’est pas affecté — non-régression', function () {
    // Le cas de très loin le plus courant : son congé est déjà sur sa ferme.
    $agent = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);

    $conge = congeClasseALOrigine($agent, '2026-06-08', '2026-06-12', 5);

    $reponse = $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $agent->id))->assertOk();

    expect((int) $reponse->viewData('totals')['leave_days_used'])->toBe(5)
        ->and($reponse->viewData('leaves')->pluck('id'))->toContain($conge->id);
});

test('la fiche ne montre pas les congés d’un AUTRE agent — non-régression', function () {
    /*
     * LA borne : on retire le filtre de FERME, pas celui de la personne. Une
     * fiche ne doit montrer que les congés de son titulaire.
     */
    $agent = agentPreteAvecDossierAilleurs($this->farm, $this->adminUser);
    congeClasseALOrigine($agent, '2026-06-08', '2026-06-12', 5);

    $autre = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'hire_date' => '2024-01-15', 'contract_end_date' => null,
    ]);
    congeClasseALOrigine($autre, '2026-07-06', '2026-07-10', 5);

    $reponse = $this->actingAs($this->adminUser)
        ->get(route('payroll.employee-history', $agent->id))->assertOk();

    expect((int) $reponse->viewData('totals')['leave_days_used'])->toBe(5)
        ->and($reponse->viewData('leaves')->pluck('employee_id')->unique()->all())->toBe([$agent->id]);
});
