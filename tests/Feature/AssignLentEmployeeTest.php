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
 * ASSIGNER UNE TÂCHE À UN AGENT PRÊTÉ RENVOYAIT UNE PAGE « OUPS ! ».
 *
 * Signalé depuis le terrain : erreur 500 sur /tasks/175/assign.
 *
 * Un agent PRÊTÉ a son dossier sur un site et son compte sur l'autre. Il est
 * délibérément proposé par `Employee::assignableInCurrentFarm()`, donc il figure
 * dans le menu « Assigner… ». Mais `belongsTo(Employee::class)` lui réappliquait
 * le filtre de ferme et renvoyait NULL. Trois conséquences :
 *
 *   1. l'affectation était ÉCRITE, puis le message de confirmation plantait en
 *      500 (« property first_name on null ») — l'utilisateur croyait avoir tout
 *      cassé alors que la tâche était bien assignée ;
 *   2. l'écran, qui teste `@if($task->employee)`, réaffichait « Assigner… »
 *      indéfiniment : la tâche paraissait ne jamais s'assigner ;
 *   3. plus discret : `Employee::find()` renvoyant null pour cet agent, les
 *      garde-fous « en congé » et « mauvais service » étaient SAUTÉS sans un mot,
 *      parce qu'ils étaient gardés par `if ($employee && …)`.
 *
 * Troisième occurrence de la même règle écrite à deux endroits : la liste
 * proposait ce que la lecture refusait. Le lien vit maintenant dans un seul
 * endroit (App\Traits\ReferencesEmployee).
 */

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);
});

/** Un agent dont le dossier est ailleurs, mais dont le compte accède à cette ferme. */
function lentAgentForTask(Farm $farm, User $sameRoleAs, string $department = 'Cultures'): Employee
{
    $elsewhere = Farm::firstOrCreate(
        ['code' => 'KER-700'],
        ['name' => 'Kérouané', 'is_active' => true]
    );

    $account = User::factory()->create(['role_id' => $sameRoleAs->role_id]);
    DB::table('farm_user')->insert([
        'farm_id' => $farm->id, 'user_id' => $account->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $employee = Employee::factory()->create([
        'farm_id'    => $elsewhere->id,
        'user_id'    => $account->id,
        'status'     => 'Actif',
        'department' => $department,
    ]);

    $employee->lendTo($farm->id, today()->subMonth());

    return $employee;
}

function unassignedTask(Farm $farm, string $category = 'irrigation'): TaskAssignment
{
    return TaskAssignment::create([
        'farm_id'        => $farm->id,
        'title'          => 'Arrosage parcelle A',
        'category'       => $category,
        'scheduled_date' => today()->toDateString(),
        'status'         => 'a_faire',
    ]);
}

test('assigner une tâche à un agent PRÊTÉ aboutit, sans page d’erreur', function () {
    $lent = lentAgentForTask($this->farm, $this->adminUser);
    $task = unassignedTask($this->farm);

    // Il est bien proposé par le sélecteur — c'est le point de départ du défaut.
    expect(Employee::assignableInCurrentFarm()->pluck('id'))->toContain($lent->id);

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $lent->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($task->fresh()->employee_id)->toBe($lent->id);
});

test('une fois assignée, la tâche SAIT qui la porte', function () {
    // Le 500 n'était que le symptôme visible. La relation renvoyant null, l'écran
    // (`@if($task->employee)`) réaffichait « Assigner… » sur une tâche pourtant
    // assignée : elle paraissait ne jamais s'assigner.
    $lent = lentAgentForTask($this->farm, $this->adminUser);
    $task = unassignedTask($this->farm);
    $task->update(['employee_id' => $lent->id]);

    expect($task->fresh()->employee)->not->toBeNull()
        ->and($task->fresh()->employee->id)->toBe($lent->id);
});

test('le garde-fou « en congé » s’applique AUSSI à un agent prêté', function () {
    // Le défaut silencieux : `find()` renvoyant null, le garde-fou était sauté.
    // On pouvait affecter une tâche à quelqu'un en congé sans un mot.
    $lent = lentAgentForTask($this->farm, $this->adminUser);
    $task = unassignedTask($this->farm);

    EmployeeLeave::create([
        'farm_id'     => $this->farm->id,
        'employee_id' => $lent->id,
        'type'        => 'Congé annuel',
        'start_date'  => today()->subDay()->toDateString(),
        'end_date'    => today()->addDay()->toDateString(),
        'days_count'  => 3,
        'status'      => 'approuve',
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $lent->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test('le garde-fou « mauvais service » s’applique AUSSI à un agent prêté', function () {
    // Une tâche d'irrigation revient aux Cultures : un agent de l'Abattoir doit
    // être refusé, prêté ou non.
    $lent = lentAgentForTask($this->farm, $this->adminUser, 'Abattoir');
    $task = unassignedTask($this->farm, 'irrigation');

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $lent->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test('un employé d’un AUTRE site, sans accès, reste refusé', function () {
    // Élargir la lecture ne doit pas ouvrir l'affectation à tous les sites.
    // `exists:employees,id` ne borne rien : le refus doit venir de la règle.
    $elsewhere = Farm::firstOrCreate(['code' => 'KER-701'], ['name' => 'Autre site', 'is_active' => true]);
    $stranger = Employee::factory()->create([
        'farm_id' => $elsewhere->id, 'user_id' => null, 'status' => 'Actif', 'department' => 'Cultures',
    ]);
    $task = unassignedTask($this->farm);

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $stranger->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test('un dossier ARCHIVÉ ne peut pas recevoir de tâche', function () {
    // `exists:employees,id` accepte une ligne archivée : sans la règle, on
    // affecterait du travail à quelqu'un qui a quitté l'exploitation.
    $gone = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif', 'department' => 'Cultures',
    ]);
    $gone->delete();

    $task = unassignedTask($this->farm);

    $this->actingAs($this->adminUser)
        ->post(route('tasks.assign', $task), ['employee_id' => $gone->id])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($task->fresh()->employee_id)->toBeNull();
});

test('le lien vers l’employé est déclaré UNE fois, pas dix', function () {
    // Dix modèles portaient la même ligne `belongsTo(Employee::class)`, donc le
    // même trou. C'est le défaut de fond de cette session : une règle, plusieurs
    // copies, qui divergent en silence.
    $models = ['StoredLotCheck', 'EmployeeAttendance', 'Harvest', 'EmployeeContractEvent',
        'CropTransformation', 'Payslip', 'Batch', 'TaskAssignment', 'EmployeeLeave', 'CropCycle'];

    foreach ($models as $model) {
        $source = file_get_contents(app_path("Models/{$model}.php"));

        expect($source)->toContain('ReferencesEmployee')
            ->and($source)->not->toContain('belongsTo(Employee::class)');
    }
});

test('un agent prêté reste hors des agrégats RH de la ferme d’accueil', function () {
    // Garde-fou de portée : rendre le lien LISIBLE ne doit pas faire entrer
    // l'agent dans l'effectif ni la masse salariale du site où il est prêté — il
    // est payé et évalué par son site d'origine.
    $lent = lentAgentForTask($this->farm, $this->adminUser);

    expect(Employee::pluck('id'))->not->toContain($lent->id);
});
