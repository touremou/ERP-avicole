<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UN CONGÉ ÉGALEMENT POINTÉ « ABSENT » ÉTAIT RETENU DEUX FOIS SUR LA PAIE.
 *
 * `PayrollService` additionne deux sources d'absence : le chevauchement des
 * congés déclarés, et les journées pointées « absent ». Il tenait le double
 * comptage pour impossible, et le disait : « les jours de congé sont pré-pointés
 * "conge" (cf. AttendanceController), donc ils ne remontent pas ici en
 * "absent" ».
 *
 * Or ce pré-pointage n'est qu'une VALEUR PAR DÉFAUT de formulaire. Rien ne
 * l'impose :
 *
 *   • le « verrou » de la grille web n'est qu'une mention textuelle — le champ
 *     reste modifiable ;
 *   • `RecordAttendance`, porte commune du web et du terrain hors-ligne,
 *     n'examine aucun congé et accepte « absent » sans réserve ;
 *   • la grille pré-remplit d'après `EmployeeLeave::approved()` = approuvé/en
 *     cours, quand la paie compte AUSSI les congés « terminé » — un pointage
 *     saisi après coup propose donc « présent » sur des jours que la paie
 *     décompte en congé.
 *
 * ─── MESURÉ, AVANT CORRECTION ───
 *
 * Cinq jours de congé SANS SOLDE, également pointés absents, sur un salaire de
 * 2 000 000 GNF :
 *
 *   jours d'absence      10   (au lieu de 5)
 *   jours travaillés     16   (au lieu de 21)
 *   retenue         769 231   (au lieu de 384 615)
 *
 * Et le bulletin remis au salarié annonçait « Absence non payée (10j) » pour un
 * congé de cinq jours. C'est de l'argent retiré à quelqu'un, sur un document
 * qu'il peut lire et contester.
 *
 * ─── LA RÈGLE ───
 *
 * La paie ne peut pas reposer sur une valeur par défaut d'écran. Elle écarte
 * elle-même, du décompte des pointages, les jours qu'elle a DÉJÀ comptés au
 * titre d'un congé.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
    $this->actingAs($this->adminUser);

    $this->salarie = Employee::factory()->create([
        'farm_id'   => $this->farm->id,
        'status'    => 'Actif',
        'salary'    => 2_000_000,
        'hire_date' => '2026-01-01',
    ]);

    $this->periode = PayrollPeriod::create([
        'farm_id'    => $this->farm->id,
        'label'      => 'Août 2026',
        'year'       => 2026,
        'month'      => 8,
        'start_date' => '2026-08-01',
        'end_date'   => '2026-08-31',
        'status'     => 'brouillon',
    ]);

    // Lundi 3 → vendredi 7 août 2026 : cinq jours ouvrés.
    $this->joursDuConge = ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'];
});

/** Un congé validé de la semaine du 3 août. */
function congeDeLaSemaine(int $farmId, int $employeeId, string $type, string $statut = 'approuve'): EmployeeLeave
{
    return EmployeeLeave::create([
        'farm_id'     => $farmId,
        'employee_id' => $employeeId,
        'type'        => $type,
        'start_date'  => '2026-08-03',
        'end_date'    => '2026-08-07',
        'days_count'  => 5,
        'status'      => $statut,
    ]);
}

/** Pointe les jours donnés au statut voulu. */
function pointer(int $farmId, int $employeeId, array $jours, string $statut): void
{
    foreach ($jours as $jour) {
        EmployeeAttendance::create([
            'farm_id'         => $farmId,
            'employee_id'     => $employeeId,
            'attendance_date' => $jour,
            'status'          => $statut,
        ]);
    }
}

/** La fiche de paie produite pour la période. */
function ficheDe(PayrollPeriod $periode, Employee $salarie): Payslip
{
    app(PayrollService::class)->generatePayroll($periode);

    return Payslip::where('payroll_period_id', $periode->id)
        ->where('employee_id', $salarie->id)
        ->firstOrFail();
}

test('un congé SANS SOLDE pointé absent n’est retenu qu’une fois', function () {
    /*
     * LE défaut, sur le chiffre que le salarié lit. Cinq jours de congé : cinq
     * jours de retenue, pas dix.
     */
    congeDeLaSemaine($this->farm->id, $this->salarie->id, 'sans_solde');
    pointer($this->farm->id, $this->salarie->id, $this->joursDuConge, 'absent');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(5);

    $retenue = $fiche->lines()->where('category', 'absence')->first();

    expect($retenue->label)->toContain('(5j)')
        ->and((int) $retenue->amount)->toBe(384_615);
});

test('un congé PAYÉ pointé absent ne devient pas une retenue', function () {
    /*
     * Le cas le plus injuste : un congé annuel, dû au salarié, se transformait
     * en absence NON PAYÉE parce que quelqu'un avait pointé « absent » —
     * `pointedAbsent` alimentait `unpaidDays` sans regarder le motif.
     */
    congeDeLaSemaine($this->farm->id, $this->salarie->id, 'conge_annuel');
    pointer($this->farm->id, $this->salarie->id, $this->joursDuConge, 'absent');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_leave)->toBe(5)
        ->and($fiche->days_absent)->toBe(0)
        ->and($fiche->lines()->where('category', 'absence')->count())->toBe(0);
});

test('une absence pointée HORS congé reste bien déduite', function () {
    /*
     * LA borne. Ne plus rien déduire serait pire que déduire deux fois : une
     * absence injustifiée doit continuer de coûter au salarié ce qu'elle coûte
     * à l'exploitation.
     */
    pointer($this->farm->id, $this->salarie->id, ['2026-08-10', '2026-08-11'], 'absent');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(2)
        ->and($fiche->lines()->where('category', 'absence')->first()->label)->toContain('(2j)');
});

test('congé ET absence distincte s’additionnent — chacun une fois', function () {
    /*
     * Le mélange des deux : la correction ne doit pas absorber une absence
     * réelle sous prétexte qu'un congé existe ailleurs dans le mois.
     */
    congeDeLaSemaine($this->farm->id, $this->salarie->id, 'sans_solde');
    pointer($this->farm->id, $this->salarie->id, $this->joursDuConge, 'absent');       // déjà en congé
    pointer($this->farm->id, $this->salarie->id, ['2026-08-17'], 'absent');            // vraie absence

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(6);   // 5 de congé + 1 d'absence, sans doublon
});

test('un congé au statut TERMINÉ compte aussi une seule fois', function () {
    /*
     * L'écart de définition qui rend le défaut atteignable en pratique : la
     * grille de pointage pré-remplit d'après `approved()` = approuvé/en cours,
     * quand la paie compte aussi « terminé ». Un pointage saisi après le retour
     * du salarié propose donc « présent » — ou « absent » — sur des jours que la
     * paie décompte déjà.
     */
    congeDeLaSemaine($this->farm->id, $this->salarie->id, 'sans_solde', 'termine');
    pointer($this->farm->id, $this->salarie->id, $this->joursDuConge, 'absent');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(5);
});

test('sans aucun pointage, le congé se déduit normalement — non-régression', function () {
    // Le cas courant ne doit pas bouger d'un franc.
    congeDeLaSemaine($this->farm->id, $this->salarie->id, 'sans_solde');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(5)
        ->and((int) $fiche->lines()->where('category', 'absence')->first()->amount)->toBe(384_615);
});

/*
 * ─────────────────────────────────────────────────────────────────────────
 * LE DÉCOMPTE SE FAIT EN JOURS OUVRÉS — le dimanche n'est pas un jour de congé.
 *
 * Le chevauchement des congés se comptait en jours CALENDAIRES, alors que la
 * retenue vaut « salaire ÷ jours OUVRÉS × jours décomptés ». Un congé qui
 * enjambe un jour de repos facturait donc ce repos au salarié, au taux d'une
 * journée de travail. Numérateur et dénominateur ne comptaient pas la même
 * chose : leur rapport n'avait pas de sens.
 * ─────────────────────────────────────────────────────────────────────────
 */

test('un congé qui ENJAMBE un dimanche ne fait pas payer le dimanche', function () {
    /*
     * Lundi 3 → lundi 10 août 2026 : 8 jours calendaires, 7 ouvrés.
     * Mesuré avant correction : 8 jours retenus, 615 385 GNF au lieu de
     * 538 462 — 76 923 GNF pour un dimanche que personne ne lui payait de
     * travailler.
     */
    EmployeeLeave::create([
        'farm_id'     => $this->farm->id,
        'employee_id' => $this->salarie->id,
        'type'        => 'sans_solde',
        'start_date'  => '2026-08-03',
        'end_date'    => '2026-08-10',
        'days_count'  => 8,
        'status'      => 'approuve',
    ]);

    $fiche = ficheDe($this->periode, $this->salarie);

    // Août 2026 : 26 jours ouvrés (31 − 5 dimanches).
    expect($fiche->days_absent)->toBe(7)
        ->and((int) $fiche->lines()->where('category', 'absence')->first()->amount)
            ->toBe((int) round(2_000_000 / 26 * 7));
});

test('une absence pointée un DIMANCHE n’est pas retenue', function () {
    /*
     * Même principe par l'autre porte : la grille de pointage s'ouvre aussi le
     * dimanche, et rien n'empêche d'y cocher « absent ». La retenue s'exprime en
     * jours ouvrés — elle ne peut pas en compter d'autres.
     */
    pointer($this->farm->id, $this->salarie->id, ['2026-08-09'], 'absent');   // un dimanche

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(0)
        ->and($fiche->lines()->where('category', 'absence')->count())->toBe(0);
});

test('un congé entièrement en semaine est inchangé — non-régression', function () {
    // La borne : sans jour de repos dans l'intervalle, rien ne doit bouger.
    congeDeLaSemaine($this->farm->id, $this->salarie->id, 'sans_solde');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(5);
});

/*
 * ─────────────────────────────────────────────────────────────────────────
 * ET D'OÙ QUE LE POINTAGE VIENNE — l'agent prêté à un autre site.
 *
 * La paie lisait `EmployeeAttendance` en direct, donc sous le scope de FERME.
 * Un agent prêté est pointé sur le site d'ACCUEIL, et ces lignes portent le
 * farm_id de ce site : la paie de son site d'origine ne les voyait pas.
 *
 * C'est le miroir du défaut principal de ce fichier. Celui-là retirait de
 * l'argent au salarié ; celui-ci en verse pour des journées non travaillées.
 * Les deux naissent d'une frontière qu'une requête respecte et que sa voisine
 * ignore — la requête des congés évitait déjà ce piège, et disait pourquoi.
 * ─────────────────────────────────────────────────────────────────────────
 */

test('une absence pointée sur le site d’ACCUEIL est bien déduite', function () {
    /*
     * Mesuré avant correction : trois absences constatées sur l'autre site,
     * ZÉRO déduite, salaire versé en entier — 230 769 GNF pour des journées où
     * l'agent était noté absent.
     */
    $autreSite = \App\Models\Farm::create([
        'code' => 'FT-KER', 'name' => 'Kérouané', 'is_active' => true,
    ]);

    foreach (['2026-08-10', '2026-08-11', '2026-08-12'] as $jour) {
        EmployeeAttendance::withoutGlobalScopes()->create([
            'farm_id'         => $autreSite->id,          // pointé ailleurs
            'employee_id'     => $this->salarie->id,
            'attendance_date' => $jour,
            'status'          => 'absent',
        ]);
    }

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(3)
        ->and((int) $fiche->lines()->where('category', 'absence')->first()->amount)
            ->toBe((int) round(2_000_000 / 26 * 3));
});

test('le pointage d’un AUTRE agent ne s’invite pas dans la fiche', function () {
    /*
     * LA borne : lever le cloisonnement sur la ferme ne doit pas lever le
     * cloisonnement sur la PERSONNE. La relation est bornée par l'agent, c'est
     * ce qui rend l'ouverture sûre.
     */
    $collegue = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
        'salary' => 1_000_000, 'hire_date' => '2026-01-01',
    ]);

    pointer($this->farm->id, $collegue->id, ['2026-08-10', '2026-08-11'], 'absent');

    $fiche = ficheDe($this->periode, $this->salarie);

    expect($fiche->days_absent)->toBe(0);
});
