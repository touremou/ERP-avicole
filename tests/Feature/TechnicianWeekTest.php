<?php

use App\Models\Batch;
use App\Models\CropCycle;
use App\Models\DailyCheck;
use App\Models\Employee;
use App\Models\Plot;
use App\Models\TaskAssignment;
use App\Services\TechnicianWeekService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * S2 — La fiche hebdomadaire par technicien.
 *
 * Le pilotage à distance repose sur des mesures que le système produit seul. Ces
 * tests verrouillent les propriétés SANS LESQUELLES un indicateur ne serait pas
 * utilisable :
 *
 *  - la complétion se calcule sur les tâches PLANIFIÉES (sinon ne rien faire
 *    donnerait 100 %) ;
 *  - la ponctualité se mesure sur la date DÉCLARÉE de l'acte, pas sur l'arrivée
 *    au serveur — sinon un site sans réseau est puni pour son réseau ;
 *  - une donnée absente vaut null (« non mesurable »), jamais 0 qui se lirait
 *    comme un résultat conforme ;
 *  - le technicien voit SA fiche et rien d'autre.
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

function weekSheet(Employee $employee): array
{
    return app(TechnicianWeekService::class)->forEmployee($employee, now()->startOfWeek());
}

/** Retrouve un indicateur par sa clé. */
function indicator(array $sheet, string $key): array
{
    foreach ($sheet['indicators'] as $ind) {
        if ($ind['key'] === $key) return $ind;
    }
    throw new RuntimeException("Indicateur {$key} absent");
}

/** Tâche planifiée pour l'employé, avec statut et date de complétion choisis. */
function taskFor(Employee $employee, string $status, ?string $scheduled = null, ?string $completedAt = null, array $extra = []): TaskAssignment
{
    return TaskAssignment::create(array_merge([
        'employee_id'    => $employee->id,
        'title'          => 'Tâche ' . Str::random(4),
        'category'       => 'controle',
        'scheduled_date' => $scheduled ?? now()->toDateString(),
        'priority'       => 'normale',
        'status'         => $status,
        'completed_at'   => $completedAt,
        'proof_type'     => 'aucune',
    ], $extra));
}

// ───────────────── COMPLÉTION ─────────────────

test('la complétion se calcule sur les tâches PLANIFIÉES de la semaine', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($employee, 'a_faire');
    // Sans le dénominateur « planifiées », ne rien faire donnerait 100 %.
    expect(indicator(weekSheet($employee), 'completion')['value'])->toBe(75.0);
});

test('une tâche hors de la semaine n’entre pas dans le calcul', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    // Semaine précédente : hors périmètre.
    taskFor($employee, 'a_faire', now()->subWeeks(2)->toDateString());

    expect(indicator(weekSheet($employee), 'completion')['value'])->toBe(100.0);
});

test('les tâches d’un AUTRE technicien ne comptent pas', function () {
    $mine = Employee::factory()->create(['status' => 'Actif']);
    $other = Employee::factory()->create(['status' => 'Actif']);

    taskFor($mine, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($other, 'a_faire');

    expect(indicator(weekSheet($mine), 'completion')['value'])->toBe(100.0);
    expect(weekSheet($mine)['tasks']['total'])->toBe(1);
});

test('sans aucune tâche planifiée, la complétion est NON MESURABLE (pas 0 %)', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    $ind = indicator(weekSheet($employee), 'completion');
    // 0 % se lirait comme « n'a rien fait » : c'est faux, il n'y avait rien.
    expect($ind['value'])->toBeNull();
    expect($ind['tone'])->toBe('neutral');
});

// ───────────────── PONCTUALITÉ (le point d'équité offline) ─────────────────

test('une tâche faite le jour prévu compte comme ponctuelle', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $day = now()->startOfWeek()->addDay();

    taskFor($employee, 'fait', $day->toDateString(), $day->copy()->setTime(9, 30)->toDateTimeString());

    expect(indicator(weekSheet($employee), 'punctuality')['value'])->toBe(100.0);
});

test('une tâche faite un autre jour que prévu n’est pas ponctuelle', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $day = now()->startOfWeek();

    taskFor($employee, 'fait', $day->toDateString(), $day->copy()->addDays(2)->toDateTimeString());

    expect(indicator(weekSheet($employee), 'punctuality')['value'])->toBe(0.0);
});

test('un push tardif hors réseau NE dégrade PAS la ponctualité', function () {
    // Le cœur de l'équité : le technicien de Kérouané fait sa tâche lundi et ne
    // capte le réseau que mercredi. Mesurer l'arrivée serveur le sanctionnerait
    // pour un problème de couverture, pas pour un manquement.
    // TEMPS FIGÉ au mercredi : le scénario décrit un travail fait LUNDI et poussé
    // MERCREDI. Sans figer, `now()->startOfWeek()` vaut aujourd'hui quand la
    // suite tourne un lundi, et l'horodatage « lundi 07:15 » devient FUTUR avant
    // 07:15 — la garde `before_or_equal:now` refuse alors la saisie et le test
    // échoue pour une raison qui n'a rien à voir avec ce qu'il vérifie.
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-29 14:00:00')); // mercredi

    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    $monday = now()->startOfWeek();

    $task = taskFor($employee, 'a_faire', $monday->toDateString());

    // Push le mercredi, avec l'horodatage DÉCLARÉ du lundi.
    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(),
        'type'    => 'task.complete',
        'payload' => [
            'uuid'    => (string) Str::uuid(),
            'task_id' => $task->id,
            'done_at' => $monday->copy()->setTime(7, 15)->toIso8601String(),
        ],
    ]]]);

    $res->assertOk();
    expect($res->json('results.0.status'))->toBe('success');

    expect($task->fresh()->completed_at->toDateString())->toBe($monday->toDateString());
    expect(indicator(weekSheet($employee), 'punctuality')['value'])->toBe(100.0);

    \Carbon\Carbon::setTestNow();
});

test('sans horodatage déclaré, on retombe sur l’heure serveur', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    $task = taskFor($employee, 'a_faire', now()->toDateString());

    $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'task.complete',
        'payload' => ['uuid' => (string) Str::uuid(), 'task_id' => $task->id],
    ]]])->assertOk();

    // Rétro-compatibilité : un client antérieur n'envoie pas done_at.
    expect($task->fresh()->completed_at)->not->toBeNull();
});

test('un horodatage déclaré dans le FUTUR est refusé', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    $task = taskFor($employee, 'a_faire', now()->toDateString());

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'task.complete',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'task_id' => $task->id,
            'done_at' => now()->addDays(3)->toIso8601String(),
        ],
    ]]]);

    // Une horloge de téléphone déréglée ne doit pas antidater l'avenir.
    expect($res->json('results.0.status'))->toBe('validation_failed');
    expect($task->fresh()->status)->toBe('a_faire');
});

// ───────────────── ÉLEVAGE : mortalité, FCR, aliment ─────────────────

test('la mortalité retenue est celle du lot le PLUS atteint', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    $calm = Batch::factory()->create(['employee_id' => $employee->id, 'initial_quantity' => 1000, 'current_quantity' => 995, 'status' => 'Actif']);
    $hit  = Batch::factory()->create(['employee_id' => $employee->id, 'initial_quantity' => 1000, 'current_quantity' => 900, 'status' => 'Actif']);

    DailyCheck::create(['batch_id' => $calm->id, 'check_date' => now()->toDateString(), 'mortality' => 5]);
    DailyCheck::create(['batch_id' => $hit->id, 'check_date' => now()->toDateString(), 'mortality' => 100]);

    $ind = indicator(weekSheet($employee), 'mortality');
    // C'est le lot le plus atteint qui appelle une action, pas la moyenne.
    expect($ind['value'])->toBe(10.0);
    expect($ind['detail'])->toContain($hit->code);
    expect($ind['tone'])->toBe('bad'); // 10 % > seuil par défaut (5 %)
});

test('sans lot sous responsabilité, mortalité et FCR sont non mesurables', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    expect(indicator(weekSheet($employee), 'mortality')['value'])->toBeNull();
    expect(indicator(weekSheet($employee), 'fcr')['value'])->toBeNull();
    expect(indicator(weekSheet($employee), 'feed_gap')['value'])->toBeNull();
});

test('le FCR est non mesurable sans pesée moyenne (et non égal à 0)', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $batch = Batch::factory()->create(['employee_id' => $employee->id, 'initial_quantity' => 500, 'current_quantity' => 500, 'status' => 'Actif']);

    // De l'aliment consommé, mais aucun poids moyen : un « 0 » se lirait comme
    // une conversion parfaite.
    DailyCheck::create(['batch_id' => $batch->id, 'check_date' => now()->toDateString(), 'feed_consumed' => 40]);

    expect($batch->fresh()->fcr_corrected)->toBeNull();
    expect(indicator(weekSheet($employee), 'fcr')['value'])->toBeNull();
});

test('le FCR corrigé impute aux morts la moitié du poids moyen', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $batch = Batch::factory()->create([
        'employee_id' => $employee->id, 'initial_quantity' => 1000,
        'current_quantity' => 900, 'status' => 'Actif',
    ]);

    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => now()->toDateString(),
        'mortality' => 100, 'feed_consumed' => 1000, 'avg_weight' => 1.0,
    ]);

    // Vivants : 900 × 1 kg = 900. Morts : 100 × 0,5 kg = 50. Total 950 kg.
    // 1000 kg d'aliment / 950 kg de biomasse ≈ 1,05.
    expect($batch->fresh()->fcr_corrected)->toBe(1.05);
});

test('le rapport technique et la fiche hebdo affichent le MÊME FCR', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $batch = Batch::factory()->create([
        'employee_id' => $employee->id, 'initial_quantity' => 1000,
        'current_quantity' => 950, 'status' => 'Actif',
    ]);
    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => now()->toDateString(),
        'mortality' => 50, 'feed_consumed' => 800, 'avg_weight' => 0.9,
    ]);

    // Un tableau de bord qui contredit un rapport n'est plus consulté : les deux
    // consomment désormais Batch::fcr_corrected.
    $sheetFcr = weekSheet($employee)['batches'][0]['fcr'];
    expect($sheetFcr)->not->toBeNull();

    // La vue du rapport formate en number_format($x, 2) — séparateur anglo-saxon.
    // On vérifie que c'est bien LA MÊME valeur qui s'y affiche.
    $this->get(route('reports.technical'))
        ->assertOk()
        ->assertSee(number_format((float) $sheetFcr, 2));
});

// ───────────────── CULTURES ─────────────────

test('les interventions d’itinéraire sont comptées à part', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);

    // Deux interventions cultures, une faite ; plus une tâche d'élevage.
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString(), ['category' => 'traitement']);
    taskFor($employee, 'en_retard', now()->toDateString(), null, ['category' => 'sarclage']);
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString(), ['category' => 'controle']);

    $ind = indicator(weekSheet($employee), 'crop_interventions');
    expect($ind['value'])->toBe(50.0);
    expect($ind['detail'])->toContain('1 sur 2');
});

test('l’avancement d’itinéraire est rapporté par cycle', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    $plot = Plot::create(['code' => 'P-S2', 'name' => 'Parcelle S2', 'area_ha' => 1, 'status' => Plot::STATUS_EN_CULTURE]);
    $cycle = CropCycle::create([
        'plot_id' => $plot->id, 'employee_id' => $employee->id, 'code' => 'CYC-S2',
        'crop_name' => 'Maïs', 'area_used_ha' => 1,
        'planting_date' => now()->subDays(30)->toDateString(), 'status' => CropCycle::STATUS_EN_COURS,
    ]);

    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString(),
        ['category' => 'fertilisation', 'crop_cycle_id' => $cycle->id, 'crop_protocol_item_id' => null]);
    taskFor($employee, 'en_retard', now()->toDateString(), null,
        ['category' => 'traitement', 'crop_cycle_id' => $cycle->id, 'crop_protocol_item_id' => null]);

    $sheet = weekSheet($employee);
    expect($sheet['cycles'])->toHaveCount(1);
    expect($sheet['cycles'][0]['crop_name'])->toBe('Maïs');
    expect($sheet['cycles'][0]['days_after_planting'])->toBe(30);
});

// ───────────────── ACCÈS ─────────────────

test('le promoteur (rh.L) voit la fiche de n’importe quel technicien et le comparatif', function () {
    $a = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Awa']);
    $b = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Bakary']);
    taskFor($a, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($b, 'a_faire');

    $this->get(route('rh.semaine', ['employee_id' => $b->id]))
        ->assertOk()
        ->assertSee('Bakary')
        ->assertSee('Comparatif de la semaine');
});

test('un technicien sans droit RH ne voit QUE sa propre fiche', function () {
    $mine = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Cissé', 'user_id' => $this->readonlyUser->id]);
    $other = Employee::factory()->create(['status' => 'Actif', 'first_name' => 'Diallo']);
    taskFor($mine, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($other, 'a_faire');

    // Rôle « viewer » : aucun droit rh.L dans la matrice de ce test.
    DB::table('module_permissions')
        ->join('modules', 'modules.id', '=', 'module_permissions.module_id')
        ->where('modules.slug', 'rh')
        ->where('module_permissions.role_id', $this->readonlyUser->role_id)
        ->update(['can_read' => false]);
    \Illuminate\Support\Facades\Cache::forget("rbac_perms_{$this->readonlyUser->id}");

    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->readonlyUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->readonlyUser);

    // Même en forçant l'id d'un collègue dans l'URL, il reste sur sa fiche.
    $this->get(route('rh.semaine', ['employee_id' => $other->id]))
        ->assertOk()
        ->assertSee('Cissé')
        ->assertDontSee('Comparatif de la semaine');
});

// ───────────────── MOBILE : l'auto-suivi du lundi ─────────────────

test('mobile : /me/week renvoie MES six indicateurs, sans droit RH', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($employee, 'a_faire');

    $res = $this->getJson('/api/v1/me/week');
    $res->assertOk();

    expect($res->json('has_sheet'))->toBeTrue();
    expect($res->json('indicators'))->toHaveCount(6);
    expect($res->json('tasks.total'))->toBe(2);
    expect($res->json('tasks.done'))->toBe(1);

    $keys = array_column($res->json('indicators'), 'key');
    expect($keys)->toBe(['completion', 'punctuality', 'mortality', 'fcr', 'feed_gap', 'crop_interventions']);
});

test('mobile : un compte sans fiche employé n’a pas de semaine personnelle', function () {
    // adminUser sans Employee rattaché.
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Laravel\Sanctum\Sanctum::actingAs($this->adminUser);

    $res = $this->getJson('/api/v1/me/week');
    $res->assertOk();
    expect($res->json('has_sheet'))->toBeFalse();
});

test('mobile et web affichent le même chiffre de complétion', function () {
    $employee = Employee::factory()->create(['status' => 'Actif', 'user_id' => $this->managerUser->id]);
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($employee, 'fait', now()->toDateString(), now()->toDateTimeString());
    taskFor($employee, 'a_faire');

    $apiValue = collect($this->getJson('/api/v1/me/week')->json('indicators'))
        ->firstWhere('key', 'completion')['value'];

    // Une seule source de calcul : le débriefing ne doit pas dériver en
    // discussion sur l'outil.
    expect((float) $apiValue)->toBe(indicator(weekSheet($employee), 'completion')['value']);
    expect((float) $apiValue)->toBe(66.7);
});

test('le comparatif écarte les techniciens sans tâche de la semaine', function () {
    $active = Employee::factory()->create(['status' => 'Actif']);
    Employee::factory()->create(['status' => 'Actif']); // aucune tâche
    taskFor($active, 'fait', now()->toDateString(), now()->toDateTimeString());

    $comparison = app(TechnicianWeekService::class)->comparison(now()->startOfWeek());

    // Trois colonnes vides dilueraient la lecture du promoteur.
    expect($comparison)->toHaveCount(1);
    expect($comparison[0]['employee']->id)->toBe($active->id);
});

test('le rapport technique et la fiche hebdo affichent la MÊME mortalité', function () {
    $employee = Employee::factory()->create(['status' => 'Actif']);
    // 20 morts au transport (qty_dead) + 30 morts en élevage sur 1 000 reçus
    // vivants : c'est le cas où les deux anciennes formules divergeaient.
    $batch = Batch::factory()->create([
        'employee_id' => $employee->id, 'initial_quantity' => 1000,
        'qty_dead' => 20, 'current_quantity' => 970, 'status' => 'Actif',
    ]);
    DailyCheck::create([
        'batch_id' => $batch->id, 'check_date' => now()->toDateString(),
        'mortality' => 30, 'feed_consumed' => 500, 'avg_weight' => 1.2,
    ]);

    // Base = initial + qty_dead = 1 020. Numérateur = 20 + 30 = 50 → 4,90 %.
    // L'ancienne formule du rapport donnait 50 / 1 000 = 5,00 %.
    expect($batch->fresh()->mortality_rate)->toBe(4.9);

    $sheetRate = weekSheet($employee)['batches'][0]['mortality_rate'];
    expect($sheetRate)->toBe(4.9);

    $this->get(route('reports.technical'))
        ->assertOk()
        ->assertSee(number_format((float) $sheetRate, 2));
});
