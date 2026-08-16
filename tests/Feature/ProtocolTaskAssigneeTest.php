<?php

use App\Models\CropCycle;
use App\Models\CropProtocol;
use App\Models\CropProtocolItem;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Plot;
use App\Models\TaskAssignment;
use App\Services\TaskSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE LIGNE, DEUX RÈGLES DIFFÉRENTES.
 *
 * Le générateur quotidien attribuait les tâches d'itinéraire technique ainsi :
 *
 *     $cycle->employee_id ?? findBestEmployeeForPlot($cycle->plot, …)
 *
 * La branche de REPLI écarte les employés en congé et ne pioche que dans le
 * vivier affectable de la ferme. La branche PRIORITAIRE — le responsable du
 * cycle — ne vérifiait rien.
 *
 * C'est pourtant la règle que le bureau applique et explique
 * (TaskController::assign : « X est en congé le … Choisissez un collègue
 * disponible »), et dont un audit précédent notait qu'on pouvait la sauter
 * « sans qu'un mot soit dit ». Le générateur la sautait à son tour — sur les
 * tâches les plus datées de toutes : un traitement phytosanitaire a une fenêtre,
 * et confié à quelqu'un d'absent, il attend son retour.
 *
 * ─── ET UNE TÂCHE QUE PERSONNE NE VOYAIT ───
 *
 * Le second défaut est plus dur. La ligne de création posait `is_pool => false`
 * EN DUR, alors que `employee_id` pouvait valoir null. La liste mobile est :
 *
 *     mes tâches  OU  (pool ET à faire)
 *
 * Une tâche sans titulaire ET non-pool ne satisfait ni l'une ni l'autre : elle
 * n'apparaissait sur AUCUN téléphone. Et la synchro ne permet de « prendre »
 * qu'une tâche de pool — donc pas moyen de la réclamer non plus. Elle existait
 * au bureau, en priorité « critique » dès qu'en retard, et le terrain ne la
 * voyait jamais.
 *
 * Le générateur de contrôles de conservation, deux fonctions plus bas, écrit
 * pourtant `is_pool => $employee === null` — la règle était déjà juste à côté.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
});

/** Un employé affectable sur la ferme du test. */
function technicien(int $farmId, string $prenom): Employee
{
    return Employee::create([
        'farm_id' => $farmId,
        'first_name' => $prenom, 'last_name' => 'Camara',
        'gender' => 'M', 'phone' => '6' . random_int(10_000_000, 99_999_999),
        'department' => 'production', 'job_title' => 'Technicien', 'contract_type' => 'cdi',
        'hire_date' => now()->subYear()->toDateString(),
        'status' => 'Actif',
    ]);
}

/** Met l'employé en congé APPROUVÉ sur la journée. */
function enConge(Employee $employe): EmployeeLeave
{
    return EmployeeLeave::create([
        'farm_id' => $employe->farm_id,
        'employee_id' => $employe->id,
        'type' => 'annuel',
        'start_date' => now()->subDays(2)->toDateString(),
        'end_date' => now()->addDays(2)->toDateString(),
        'status' => 'approuve',
        'days_count' => 5,
    ]);
}

/** Cycle de maïs semé il y a 30 j, avec une étape échue aujourd'hui. */
function cycleAvecEtapeEchue(?int $employeeId): CropCycle
{
    $protocol = CropProtocol::create([
        'crop_name' => 'Maïs', 'agro_zone' => 'Basse-Guinée',
        'name' => 'Itinéraire maïs pluvial', 'is_active' => true,
    ]);

    CropProtocolItem::create([
        'crop_protocol_id' => $protocol->id,
        'day_number' => 30,
        'action_name' => 'Traitement phytosanitaire',
        'type' => 'traitement',
        'product_suggested' => 'Lambda-cyhalothrine',
        'dose' => '0,5 l/ha',
    ]);

    $plot = Plot::create([
        'code' => 'P-' . Str::upper(Str::random(4)), 'name' => 'Parcelle maïs',
        'area_ha' => 1.0, 'status' => Plot::STATUS_EN_CULTURE,
    ]);

    return CropCycle::create([
        'plot_id' => $plot->id,
        'crop_protocol_id' => $protocol->id,
        'employee_id' => $employeeId,
        'code' => 'MAI-' . Str::upper(Str::random(4)),
        'crop_name' => 'Maïs',
        'area_used_ha' => 1.0,
        'planting_date' => now()->subDays(30)->toDateString(),
        'status' => CropCycle::STATUS_EN_COURS,
    ]);
}

function genererLesTaches(): array
{
    return app(TaskSchedulerService::class)->generateForDate(now(), session('current_farm_id'));
}

/** La tâche d'itinéraire produite par le générateur. */
function tacheItineraire(): ?TaskAssignment
{
    return TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
}

test('le responsable du cycle EN CONGÉ ne reçoit pas la tâche', function () {
    /*
     * LE défaut : la branche prioritaire ne vérifiait rien. Un traitement
     * phytosanitaire a une fenêtre — confié à un absent, il attend son retour.
     */
    $absent = technicien($this->farm->id, 'Mamadou');
    enConge($absent);
    $present = technicien($this->farm->id, 'Fatoumata');

    cycleAvecEtapeEchue($absent->id);
    genererLesTaches();

    expect(tacheItineraire()?->employee_id)->not->toBe($absent->id);
});

test('la tâche va au collègue DISPONIBLE plutôt que nulle part', function () {
    // Écarter l'absent ne suffit pas : le travail doit trouver quelqu'un.
    $absent = technicien($this->farm->id, 'Mamadou');
    enConge($absent);
    $present = technicien($this->farm->id, 'Fatoumata');

    cycleAvecEtapeEchue($absent->id);
    genererLesTaches();

    expect(tacheItineraire()?->employee_id)->toBe($present->id);
});

test('le responsable DISPONIBLE garde bien sa tâche', function () {
    // La borne : la continuité du suivi reste la règle par défaut, c'est tout
    // l'intérêt d'avoir un responsable de cycle.
    $responsable = technicien($this->farm->id, 'Mamadou');
    technicien($this->farm->id, 'Fatoumata');

    cycleAvecEtapeEchue($responsable->id);
    genererLesTaches();

    expect(tacheItineraire()?->employee_id)->toBe($responsable->id);
});

test('un responsable ARCHIVÉ ne reçoit plus rien', function () {
    /*
     * Le vivier est déjà `Employee::assignableInFarm()` ; la branche
     * prioritaire le court-circuitait et pouvait désigner un dossier parti.
     */
    $parti = technicien($this->farm->id, 'Mamadou');
    $present = technicien($this->farm->id, 'Fatoumata');

    $cycle = cycleAvecEtapeEchue($parti->id);
    $parti->delete();   // dossier archivé

    genererLesTaches();

    expect(tacheItineraire()?->employee_id)->toBe($present->id);
});

test('sans titulaire possible, la tâche part au POOL et non dans le vide', function () {
    /*
     * LE second défaut, celui que personne ne voyait. La liste mobile est
     * « mes tâches OU (pool ET à faire) » : sans titulaire ET non-pool, la
     * tâche n'apparaissait sur AUCUN téléphone — et la synchro ne permet de
     * « prendre » qu'une tâche de pool, donc pas moyen de la réclamer.
     */
    $seul = technicien($this->farm->id, 'Mamadou');
    enConge($seul);

    cycleAvecEtapeEchue($seul->id);
    genererLesTaches();

    $tache = tacheItineraire();

    expect($tache)->not->toBeNull()
        ->and($tache->employee_id)->toBeNull()
        ->and($tache->is_pool)->toBeTrue();
});

test('une tâche du pool est bien VISIBLE au terrain', function () {
    /*
     * L'enjeu mesuré là où il compte : la liste que le téléphone reçoit.
     * Avant, cette tâche — priorité critique dès qu'en retard — n'y figurait
     * pas.
     */
    $seul = technicien($this->farm->id, 'Mamadou');
    enConge($seul);
    // Le lien vit sur `employees.user_id` : c'est par là que l'API mobile
    // retrouve l'employé du compte connecté.
    $compte = \App\Models\User::factory()->create(['role_id' => $this->managerUser->role_id]);
    $seul->update(['user_id' => $compte->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $compte->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    cycleAvecEtapeEchue($seul->id);
    genererLesTaches();

    \Laravel\Sanctum\Sanctum::actingAs($compte);
    $liste = $this->getJson('/api/v1/tasks')->assertOk()->json();

    $titres = collect(data_get($liste, 'tasks', data_get($liste, 'data', $liste)))
        ->pluck('title')->filter()->implode(' | ');

    expect($titres)->toContain('Traitement phytosanitaire');
});

test('une tâche ATTRIBUÉE n’est pas marquée pool', function () {
    // L'excès inverse : tout envoyer au pool ferait perdre le suivi nominatif.
    $responsable = technicien($this->farm->id, 'Mamadou');

    cycleAvecEtapeEchue($responsable->id);
    genererLesTaches();

    expect(tacheItineraire()?->is_pool)->toBeFalse();
});
