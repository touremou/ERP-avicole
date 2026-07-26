<?php

use App\Models\CropCycle;
use App\Models\CropProtocol;
use App\Models\CropProtocolCompletion;
use App\Models\CropProtocolItem;
use App\Models\Employee;
use App\Models\Plot;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Services\CropProtocolAlertService;
use App\Services\TaskSchedulerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * S1 — L'itinéraire technique pilote le calendrier.
 *
 * Le moteur phénologique existait (CropProtocolAlertService projette les étapes
 * en jours après semis) mais il était en LECTURE SEULE : ces étapes n'existaient
 * ni au calendrier, ni dans le taux de complétion. Un technicien pouvait donc
 * afficher 100 % de complétion en ayant manqué chaque intervention de la saison
 * — l'indicateur central du pilotage à distance était structurellement faux.
 *
 * Ces tests verrouillent les quatre propriétés qui rendent la mesure honnête :
 * la matérialisation, la DATE (un retard doit rester visible au jour prévu),
 * l'idempotence (le générateur tourne chaque jour) et la boucle de retour
 * (cocher la tâche valide l'étape, sinon les deux vues se contredisent).
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

/**
 * Cycle de maïs semé il y a $daysAgo jours, doté d'un itinéraire dont les étapes
 * sont données en [jour après semis => [type, nom, produit, dose]].
 */
function cycleWithProtocol(int $daysAgo, array $steps, ?int $employeeId = null): CropCycle
{
    $protocol = CropProtocol::create([
        'crop_name' => 'Maïs', 'agro_zone' => 'Basse-Guinée',
        'name' => 'Itinéraire maïs pluvial', 'is_active' => true,
    ]);

    foreach ($steps as $day => $step) {
        CropProtocolItem::create([
            'crop_protocol_id'  => $protocol->id,
            'day_number'        => $day,
            'stage'             => $step['stage'] ?? null,
            'action_name'        => $step['name'],
            'type'              => $step['type'],
            'product_suggested' => $step['product'] ?? null,
            'dose'              => $step['dose'] ?? null,
            'method'            => $step['method'] ?? null,
        ]);
    }

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
        'planting_date' => now()->subDays($daysAgo)->toDateString(),
        'status' => CropCycle::STATUS_EN_COURS,
    ]);
}

function runScheduler(?\Carbon\Carbon $date = null): array
{
    // session() et non $this->farm : une fonction au niveau module ne peut pas
    // lire une propriété protégée du cas de test (setUpRbac pose la ferme).
    return app(TaskSchedulerService::class)->generateForDate($date ?? now(), session('current_farm_id'));
}

// ───────────── MATÉRIALISATION : l'étape devient une tâche ─────────────

test('une étape d’itinéraire échue devient une vraie tâche du calendrier', function () {
    // Semé il y a 30 j, étape prévue J+30 → due aujourd'hui.
    cycleWithProtocol(30, [
        30 => ['type' => 'fertilisation', 'name' => 'Apport urée', 'product' => 'Urée 46%', 'dose' => '150 kg/ha'],
    ]);

    runScheduler();

    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toContain('Apport urée');
    expect($task->category)->toBe('fertilisation');
    // La consigne descend avec la tâche : produit ET dose, lisibles au champ.
    expect($task->description)->toContain('Urée 46%');
    expect($task->description)->toContain('150 kg/ha');
    expect($task->description)->toContain('J+30');
});

test('une étape À VENIR ne génère rien (le calendrier reste lisible)', function () {
    // Semé il y a 10 j, étapes à J+30 et J+60 : rien d'échu.
    cycleWithProtocol(10, [
        30 => ['type' => 'fertilisation', 'name' => 'Apport urée'],
        60 => ['type' => 'recolte', 'name' => 'Récolte'],
    ]);

    runScheduler();

    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->count())->toBe(0);
});

test('une étape EN RETARD est datée au jour prévu, pas au jour de génération', function () {
    // Semé il y a 40 j, étape J+30 → en retard de 10 jours.
    $cycle = cycleWithProtocol(40, [
        30 => ['type' => 'traitement', 'name' => 'Traitement chenilles'],
    ]);

    runScheduler();

    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
    $expected = now()->subDays(40)->addDays(30)->toDateString();

    // Dater au jour de génération effacerait le retard du calendrier et
    // fausserait la ponctualité — c'est exactement l'écart qu'on veut voir.
    expect($task->scheduled_date->toDateString())->toBe($expected);
    expect($task->priority)->toBe('critique');
    expect($task->crop_cycle_id)->toBe($cycle->id);
});

test('le générateur tourne chaque jour SANS dupliquer l’étape', function () {
    cycleWithProtocol(35, [
        30 => ['type' => 'sarclage', 'name' => 'Premier sarclage'],
    ]);

    runScheduler();
    runScheduler();
    runScheduler(now()->addDay());

    // Une étape reste « overdue » plusieurs jours : sans clé (cycle, étape) le
    // générateur en créerait une par jour de retard.
    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->count())->toBe(1);
});

test('la tâche va au responsable du cycle quand il est désigné', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    cycleWithProtocol(30, [
        30 => ['type' => 'irrigation', 'name' => 'Irrigation d’appoint'],
    ], $employee->id);

    runScheduler();

    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->value('employee_id'))
        ->toBe($employee->id);
});

// ───────────── PREUVE : ce qui n'est pas vérifiable à distance ─────────────

test('un traitement phytosanitaire exige une PHOTO', function () {
    cycleWithProtocol(30, [
        30 => ['type' => 'traitement', 'name' => 'Traitement fongique', 'product' => 'Mancozèbe', 'dose' => '2,5 g/L'],
    ]);

    runScheduler();

    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
    // Acte le moins vérifiable à distance, et celui qui engage un délai avant
    // récolte : sur un site sans binôme, la photo horodatée est le seul élément
    // objectif disponible.
    expect($task->proof_type)->toBe('photo');
    expect($task->requiresProof())->toBeTrue();
});

test('une observation exige une VALEUR chiffrée, pas un « j’ai regardé »', function () {
    cycleWithProtocol(21, [
        21 => ['type' => 'observation', 'name' => 'Observation phytosanitaire'],
    ]);

    runScheduler();

    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
    expect($task->proof_type)->toBe('valeur');
    expect($task->proof_unit)->toBe('pieds');
});

test('un sarclage n’exige aucune preuve (on ne surcharge pas le terrain)', function () {
    cycleWithProtocol(30, [
        30 => ['type' => 'sarclage', 'name' => 'Sarclage'],
    ]);

    runScheduler();

    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->value('proof_type'))->toBe('aucune');
});

// ───────────── BOUCLE DE RETOUR : les deux vues doivent s'accorder ─────────────

test('cocher la tâche valide l’étape d’itinéraire (web)', function () {
    $cycle = cycleWithProtocol(30, [
        30 => ['type' => 'sarclage', 'name' => 'Sarclage manuel'],
    ]);

    runScheduler();
    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();

    $this->post(route('tasks.complete', $task), ['notes' => 'Fait avec 3 ouvriers'])
        ->assertRedirect();

    $completion = CropProtocolCompletion::where('crop_cycle_id', $cycle->id)->first();
    expect($completion)->not->toBeNull();
    expect($completion->crop_protocol_item_id)->toBe($task->crop_protocol_item_id);
    expect($completion->completed_by)->toBe($this->managerUser->id);
    expect($completion->notes)->toBe('Fait avec 3 ouvriers');
});

test('l’étape validée passe « done » et n’est PLUS régénérée', function () {
    $cycle = cycleWithProtocol(35, [
        30 => ['type' => 'sarclage', 'name' => 'Sarclage manuel'],
    ]);

    runScheduler();
    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
    $this->post(route('tasks.complete', $task), [])->assertRedirect();

    // La boucle est fermée : l'itinéraire voit l'étape faite…
    $schedule = app(CropProtocolAlertService::class)->getCycleSchedule($cycle->fresh());
    expect($schedule[0]['status'])->toBe('done');

    // …et le générateur ne recrée rien, même des jours plus tard.
    runScheduler(now()->addDays(5));
    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->count())->toBe(1);
});

test('une étape déjà validée AVANT la génération ne crée aucune tâche', function () {
    $cycle = cycleWithProtocol(35, [
        30 => ['type' => 'fertilisation', 'name' => 'Apport NPK'],
    ]);
    $item = CropProtocolItem::first();

    CropProtocolCompletion::create([
        'crop_cycle_id' => $cycle->id,
        'crop_protocol_item_id' => $item->id,
        'completed_at' => now()->subDay(),
        'completed_by' => $this->managerUser->id,
    ]);

    runScheduler();

    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->count())->toBe(0);
});

test('mobile : cocher une étape au terrain la valide dans le registre du cycle', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    $cycle = cycleWithProtocol(30, [
        30 => ['type' => 'sarclage', 'name' => 'Sarclage'],
    ], $employee->id);

    runScheduler();
    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'task.complete',
        'payload' => ['uuid' => (string) Str::uuid(), 'task_id' => $task->id, 'notes' => 'Fait au champ'],
    ]]]);

    $res->assertOk();
    expect($res->json('results.0.status'))->toBe('success');

    // Même point de vérité que le web : une seule Action de validation.
    expect(CropProtocolCompletion::where('crop_cycle_id', $cycle->id)->count())->toBe(1);
});

test('mobile : la liste de tâches porte la consigne et le cycle d’origine', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    $cycle = cycleWithProtocol(30, [
        30 => ['type' => 'traitement', 'name' => 'Traitement fongique', 'product' => 'Mancozèbe', 'dose' => '2,5 g/L'],
    ], $employee->id);

    runScheduler();

    $tasks = $this->getJson('/api/v1/tasks')->json('tasks');

    expect($tasks)->toHaveCount(1);
    expect($tasks[0]['description'])->toContain('Mancozèbe');
    expect($tasks[0]['description'])->toContain('2,5 g/L');
    expect($tasks[0]['crop_cycle_id'])->toBe($cycle->id);
    expect($tasks[0]['proof_type'])->toBe('photo');
});

// ───────────── BRUIT CALENDAIRE : la mesure doit rester crédible ─────────────

test('un template restreint à des mois ne se déclenche pas hors saison', function () {
    $tpl = TaskTemplate::create([
        'name' => 'Arrosage', 'category' => 'irrigation', 'frequency' => 'quotidien',
        'target_type' => 'farm', 'priority' => 'normale', 'is_active' => true,
        // Saison sèche uniquement.
        'months' => [11, 12, 1, 2, 3, 4],
    ]);

    // Juillet (pleine saison des pluies) : la tâche n'a pas d'objet.
    expect($tpl->shouldRunOnDay(\Carbon\Carbon::parse('2026-07-15')))->toBeFalse();
    // Janvier : elle reprend.
    expect($tpl->shouldRunOnDay(\Carbon\Carbon::parse('2026-01-15')))->toBeTrue();
});

test('sans restriction de mois, un template tourne toute l’année (inchangé)', function () {
    $tpl = TaskTemplate::create([
        'name' => 'Contrôle', 'category' => 'controle', 'frequency' => 'quotidien',
        'target_type' => 'farm', 'priority' => 'normale', 'is_active' => true,
    ]);

    // Non-régression : le comportement historique ne bouge pas.
    expect($tpl->months_label)->toBe('Toute l\'année');
    foreach ([1, 4, 7, 10] as $month) {
        expect($tpl->shouldRunOnDay(\Carbon\Carbon::create(2026, $month, 15)))->toBeTrue();
    }
});

test('un template « ponctuel » ne s’auto-génère jamais — la récolte vient de l’itinéraire', function () {
    // target_type volontairement 'farm' : sous sqlite la contrainte CHECK
    // héritée n'admet pas encore 'plot' (l'ENUM n'est étendu que sous MySQL,
    // cf. migration 2026_06_22_000010). Sans incidence ici — c'est la FRÉQUENCE
    // qu'on teste.
    $tpl = TaskTemplate::create([
        'name' => 'Récolte', 'category' => 'recolte', 'frequency' => 'ponctuel',
        'target_type' => 'farm', 'priority' => 'critique', 'is_active' => true,
    ]);

    foreach ([1, 6, 12] as $month) {
        expect($tpl->shouldRunOnDay(\Carbon\Carbon::create(2026, $month, 15)))->toBeFalse();
    }

    // …mais l'étape « recolte » de l'itinéraire, elle, produit bien la tâche.
    cycleWithProtocol(95, [
        90 => ['type' => 'recolte', 'name' => 'Première récolte'],
    ]);
    runScheduler();

    $task = TaskAssignment::whereNotNull('crop_protocol_item_id')->first();
    expect($task)->not->toBeNull();
    expect($task->category)->toBe('recolte');
});

test('un cycle sans itinéraire ou sans date de semis ne génère aucune étape', function () {
    $plot = Plot::create([
        'code' => 'P-NUE', 'name' => 'Parcelle sans protocole',
        'area_ha' => 1.0, 'status' => Plot::STATUS_EN_CULTURE,
    ]);
    CropCycle::create([
        'plot_id' => $plot->id, 'code' => 'NUE-1', 'crop_name' => 'Arachide',
        'area_used_ha' => 1.0, 'planting_date' => now()->subDays(50)->toDateString(),
        'status' => CropCycle::STATUS_EN_COURS,
    ]);

    runScheduler();

    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->count())->toBe(0);
});

test('un cycle CLOS ne génère plus d’étape', function () {
    $cycle = cycleWithProtocol(40, [
        30 => ['type' => 'sarclage', 'name' => 'Sarclage'],
    ]);
    $cycle->update(['status' => CropCycle::STATUS_TERMINE]);

    runScheduler();

    expect(TaskAssignment::whereNotNull('crop_protocol_item_id')->count())->toBe(0);
});
