<?php

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Farm;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * AGENT PRÊTÉ — la règle vivait dans les sélecteurs, pas ailleurs.
 *
 * Signalé depuis le terrain, en trois symptômes qui n'en font qu'un :
 *
 *   1. « fiche congés, liste d'employé vide » — le sélecteur des congés
 *      interrogeait `Employee::where('status','Actif')`, filtré par ferme. Sur un
 *      site tenu par des agents PRÊTÉS, la liste était entièrement vide : aucun
 *      congé n'y était saisissable.
 *
 *   2. « la génération des tâches n'affecte pas automatiquement » — le vivier du
 *      générateur filtrait `farm_id` EN DUR. Vide pour la même raison, il créait
 *      toutes les tâches sans titulaire, alors que `findBestEmployee()` sait les
 *      répartir. D'où l'assignation manuelle, une par une.
 *
 *   3. « la question des prêts est vraiment complexe » — c'est le diagnostic
 *      exact : la règle « qui peut travailler ici » était réécrite à chaque
 *      endroit qui en avait besoin, et chaque copie l'oubliait à sa façon.
 *
 * Elle s'exprime désormais une fois, avec une variante pour les traitements de
 * fond qui tournent sans session (scopeAssignableInFarm).
 *
 * DÉCISION MÉTIER retenue avec le promoteur : un congé est classé au DOSSIER de
 * l'agent, donc sur son site d'ORIGINE — celui qui le paie. Le site d'accueil
 * peut le saisir et le traiter, mais la paie qui doit compter les jours le voit,
 * et l'agent n'est jamais « en congé ici, disponible là-bas ».
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

/** Agent dont le dossier vit ailleurs, mais dont le compte accède à cette ferme. */
function lentAgent(Farm $host, User $sameRoleAs, string $department = 'Cultures'): Employee
{
    $home = Farm::firstOrCreate(['code' => 'KER-800'], ['name' => 'Kérouané', 'is_active' => true]);

    $account = User::factory()->create(['role_id' => $sameRoleAs->role_id]);
    DB::table('farm_user')->insert([
        'farm_id' => $host->id, 'user_id' => $account->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $employee = Employee::factory()->create([
        'farm_id'    => $home->id,
        'user_id'    => $account->id,
        'status'     => 'Actif',
        'department' => $department,
    ]);

    // La mise à disposition est désormais DÉCLARÉE. Auparavant elle se déduisait
    // du seul accès du compte au site — un effet de bord que personne n'avait
    // décidé, et qui a fui dans une dizaine d'écrans.
    $employee->lendTo($host->id, today()->subMonth());

    return $employee;
}

test('l’écran des congés propose les agents prêtés', function () {
    // Le symptôme signalé : sur un site tenu par des agents prêtés, le menu
    // « Employé… » ne contenait rien du tout.
    $lent = lentAgent($this->farm, $this->adminUser);

    $response = $this->actingAs($this->adminUser)->get(route('payroll.leaves'));

    $response->assertOk();
    expect($response->viewData('employees')->pluck('id'))->toContain($lent->id);
});

test('un congé saisi ici est classé au site d’ORIGINE de l’agent', function () {
    // Décision métier : le congé suit la personne. Classé sur le site de saisie,
    // il aurait échappé à la paie qui doit le compter.
    $lent = lentAgent($this->farm, $this->adminUser);

    $this->actingAs($this->adminUser)->post(route('payroll.leaves.store'), [
        'employee_id' => $lent->id,
        'type'        => 'conge_annuel',
        'start_date'  => today()->toDateString(),
        'end_date'    => today()->addDays(2)->toDateString(),
    ])->assertRedirect();

    $leave = EmployeeLeave::withoutGlobalScopes()->where('employee_id', $lent->id)->first();

    expect($leave)->not->toBeNull()
        ->and($leave->farm_id)->toBe($lent->farm_id)
        ->and($leave->farm_id)->not->toBe($this->farm->id);
});

test('le congé saisi ici reste VISIBLE ici', function () {
    // Sans quoi on saisit un congé et il disparaît de la liste où on vient de
    // l'inscrire : l'utilisateur croirait que rien n'a été enregistré.
    $lent = lentAgent($this->farm, $this->adminUser);

    $this->actingAs($this->adminUser)->post(route('payroll.leaves.store'), [
        'employee_id' => $lent->id,
        'type'        => 'maladie',
        'start_date'  => today()->toDateString(),
        'end_date'    => today()->addDay()->toDateString(),
    ])->assertRedirect();

    $response = $this->actingAs($this->adminUser)->get(route('payroll.leaves'));

    expect($response->viewData('leaves')->pluck('employee_id'))->toContain($lent->id)
        // Les compteurs du haut doivent porter sur le même périmètre que la
        // liste : sinon ils annoncent des congés introuvables en dessous.
        ->and($response->viewData('kpi')['this_month'])->toBeGreaterThan(0);
});

test('un congé affiché ici est ACTIONNABLE ici', function () {
    // « Listé mais pas actionnable » : le binding de route, filtré par ferme,
    // aurait renvoyé 404 sur un congé pourtant sous les yeux.
    $lent = lentAgent($this->farm, $this->adminUser);

    $leave = EmployeeLeave::create([
        'farm_id'     => $lent->farm_id,        // classé à son site d'origine
        'employee_id' => $lent->id,
        'type'        => 'conge_annuel',
        'start_date'  => today()->addWeek()->toDateString(),
        'end_date'    => today()->addWeek()->addDays(2)->toDateString(),
        'days_count'  => 3,
        'status'      => 'demande',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.approve', $leave))
        ->assertRedirect();

    expect($leave->fresh()->status)->toBe('approuve');
});

test('être en congé ne dépend pas du site d’où l’on regarde', function () {
    // Le cœur de la décision. Un congé classé à Kérouané doit rendre l'agent
    // indisponible à Kindia aussi — sinon le garde-fou d'affectation, corrigé
    // hier, resterait sans effet pour exactement les agents concernés.
    $lent = lentAgent($this->farm, $this->adminUser);

    EmployeeLeave::create([
        'farm_id'     => $lent->farm_id,
        'employee_id' => $lent->id,
        'type'        => 'conge_annuel',
        'start_date'  => today()->subDay()->toDateString(),
        'end_date'    => today()->addDay()->toDateString(),
        'days_count'  => 3,
        'status'      => 'approuve',
    ]);

    // Vu depuis la ferme d'ACCUEIL.
    expect($lent->fresh()->isOnLeaveOn(now()))->toBeTrue();

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'title' => 'Arrosage', 'category' => 'irrigation',
        'scheduled_date' => today()->toDateString(), 'status' => 'a_faire',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $lent->id])
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test('la génération auto-affecte les agents prêtés', function () {
    // Le vivier du générateur filtrait `farm_id` en dur : vide sur un site tenu
    // par des agents prêtés, il créait tout sans titulaire.
    $lent = lentAgent($this->farm, $this->adminUser);

    $pool = Employee::assignableInFarm($this->farm->id)->pluck('id');

    expect($pool)->toContain($lent->id);
});

test('la règle du vivier est UNE, avec une variante hors session', function () {
    // Les traitements de fond tournent sans session : sans variante par ferme,
    // ils réécrivaient le filtre à la main — et c'est ainsi que la règle a
    // divergé. Le générateur ne doit plus filtrer farm_id lui-même.
    $scheduler = file_get_contents(app_path('Services/TaskSchedulerService.php'));

    expect($scheduler)->toContain('Employee::assignableInFarm($farmId)')
        ->and($scheduler)->not->toContain("\$employeeQuery->where('farm_id', \$farmId)");

    // Et la variante « ferme courante » délègue à la variante par ferme : une
    // seule expression de la règle, pas deux qui se ressemblent.
    $employee = file_get_contents(app_path('Models/Employee.php'));

    expect($employee)->toContain("return \$query->assignableInFarm(session('current_farm_id'))")
        ->and($employee)->toContain("return \$query->visibleInFarm(session('current_farm_id'))");
});

test('la paie compte un congé saisi depuis le site d’accueil', function () {
    // La conséquence financière de la décision : sans le lien déscopé, un
    // sans-solde saisi à Kindia ne serait pas déduit par la paie de Kérouané.
    $lent = lentAgent($this->farm, $this->adminUser);

    EmployeeLeave::create([
        'farm_id'     => $lent->farm_id,
        'employee_id' => $lent->id,
        'type'        => 'sans_solde',
        'start_date'  => today()->toDateString(),
        'end_date'    => today()->addDays(2)->toDateString(),
        'days_count'  => 3,
        'status'      => 'approuve',
    ]);

    // Vu depuis la ferme d'accueil comme depuis celle d'origine.
    expect($lent->fresh()->leaves()->count())->toBe(1);

    session(['current_farm_id' => $lent->farm_id]);
    expect($lent->fresh()->leaves()->count())->toBe(1);
});

test('un agent d’un autre site SANS accès reste hors du vivier', function () {
    // Garde-fou : élargir aux agents prêtés ne doit pas ouvrir tous les sites.
    $elsewhere = Farm::firstOrCreate(['code' => 'KER-801'], ['name' => 'Autre site', 'is_active' => true]);
    $stranger = Employee::factory()->create([
        'farm_id' => $elsewhere->id, 'user_id' => null, 'status' => 'Actif',
    ]);

    expect(Employee::assignableInFarm($this->farm->id)->pluck('id'))->not->toContain($stranger->id);

    $response = $this->actingAs($this->adminUser)->get(route('payroll.leaves'));
    expect($response->viewData('employees')->pluck('id'))->not->toContain($stranger->id);
});

/*
 * SUITE — signalé le même jour : « erreur pour délégation de tâche pour un
 * employé en congé » (404 sur /payroll/leaves/1/delegate).
 *
 * Le congé s'ouvrait bien, le menu « Collègue » proposait bien l'agent : c'est
 * la RELECTURE du délégataire qui le refusait. Quatre écrans partageaient cet
 * écart entre ce qui est proposé et ce qui est relu — chacun avec un symptôme
 * différent, du 404 au message tronqué.
 */

test('déléguer les tâches à un collègue PRÊTÉ aboutit', function () {
    // Le signalement exact : page « INTROUVABLE » alors que le collègue venait
    // d'être proposé dans le menu du même écran.
    $absent    = lentAgent($this->farm, $this->adminUser);
    $colleague = lentAgent($this->farm, $this->adminUser);

    $leave = EmployeeLeave::create([
        'farm_id'     => $absent->farm_id,
        'employee_id' => $absent->id,
        'type'        => 'conge_annuel',
        'start_date'  => today()->toDateString(),
        'end_date'    => today()->addDays(2)->toDateString(),
        'days_count'  => 3,
        'status'      => 'approuve',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $absent->id,
        'title' => 'Arrosage', 'category' => 'irrigation',
        'scheduled_date' => today()->addDay()->toDateString(), 'status' => 'a_faire',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.delegate', $leave), ['delegate_to' => $colleague->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    // Et la tâche a réellement changé de main : un message de succès sur une
    // délégation qui n'a rien déplacé serait pire que le 404.
    expect($task->fresh()->employee_id)->toBe($colleague->id);
});

test('déléguer à quelqu’un hors de la ferme est refusé, proprement', function () {
    // Le refus doit rester, mais en le DISANT — pas par une page introuvable.
    $absent = lentAgent($this->farm, $this->adminUser);
    $elsewhere = Farm::firstOrCreate(['code' => 'KER-802'], ['name' => 'Ailleurs', 'is_active' => true]);
    $stranger = Employee::factory()->create([
        'farm_id' => $elsewhere->id, 'user_id' => null, 'status' => 'Actif',
    ]);

    $leave = EmployeeLeave::create([
        'farm_id' => $absent->farm_id, 'employee_id' => $absent->id, 'type' => 'conge_annuel',
        'start_date' => today()->toDateString(), 'end_date' => today()->addDay()->toDateString(),
        'days_count' => 2, 'status' => 'approuve',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('payroll.leaves.delegate', $leave), ['delegate_to' => $stranger->id])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('affecter des lots à un agent prêté NOMME l’agent dans le compte-rendu', function () {
    // Symptôme discret du même écart : l'affectation avait lieu, mais le message
    // annonçait « affecté(s) à . » — on ne savait pas si ça avait marché.
    $lent = lentAgent($this->farm, $this->adminUser);

    $batch = \App\Models\Batch::factory()->create(['farm_id' => $this->farm->id]);

    $this->actingAs($this->adminUser)
        ->post(route('batches.bulkAssign'), [
            'batch_ids'   => [$batch->id],
            'employee_id' => $lent->id,
        ])
        ->assertRedirect();

    expect(session('success'))->toContain($lent->first_name);
});

test('cocher un agent prêté dans l’annuaire ne le fait pas disparaître en silence', function () {
    // L'annuaire propose les agents prêtés (règle de visibilité), mais l'action
    // les relisait filtrés par ferme : on en cochait cinq, le compte-rendu en
    // annonçait trois, sans dire lesquels ni pourquoi.
    $controller = file_get_contents(app_path('Http/Controllers/EmployeeAccessController.php'));

    expect($controller)->toContain('Employee::visibleInCurrentFarm()->whereIn(')
        ->and($controller)->not->toContain("Employee::whereIn('id', \$data['employee_ids'])");
});
