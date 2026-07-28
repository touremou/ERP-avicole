<?php

use App\Models\Employee;
use App\Models\Farm;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX REFUS DE SYNCHRONISATION QUI CONDAMNAIENT DES SAISIES DE TERRAIN.
 *
 * Signalés depuis le terrain (bac « À corriger », trois saisies bloquées) :
 *
 * 1. « Le champ rows.0.employee_id sélectionné est invalide » — sur les TROIS
 *    lignes d'une feuille de présence.
 *
 *    Le miroir mobile est alimenté par `Employee::scopeActiveForSync`, qui appelle
 *    `assignableInCurrentFarm()` : elle inclut délibérément les agents PRÊTÉS,
 *    ceux dont le COMPTE a accès à cette ferme alors que leur dossier reste
 *    rattaché à leur site d'origine. La validation du push exigeait, elle,
 *    `employees.farm_id = ferme courante`.
 *
 *    Le téléphone PROPOSAIT donc des employés que le serveur REFUSAIT. Même
 *    défaut que « listé mais pas ouvrable » corrigé côté web : une règle de
 *    visibilité, deux implémentations — sauf qu'ici elles se contredisaient au
 *    point de rendre le pointage impossible.
 *
 * 2. « Le champ done at doit être une date antérieure ou égale au now » — sur
 *    deux clôtures de tâche.
 *
 *    `before_or_equal:now` refusait une saisie dès que l'horloge du téléphone
 *    avait quelques secondes d'avance, ce qui est la NORME sur les appareils de
 *    terrain. Et un `validation_failed` est TERMINAL : la saisie partait au bac
 *    « À corriger », d'où le seul geste offert était de l'abandonner. Un travail
 *    de terrain valide était jeté pour une dérive d'horloge.
 */

beforeEach(function () {
    $this->setUpRbac();

    // Le contexte de ferme d'une requête API vient de X-Farm-Id, que le
    // middleware n'accepte que si le COMPTE a accès à cette ferme (farm_user).
    foreach ([$this->adminUser, $this->readonlyUser] as $user) {
        DB::table('farm_user')->updateOrInsert(
            ['farm_id' => $this->farm->id, 'user_id' => $user->id],
            ['is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]
        );
    }
});

/** Pousse une opération et renvoie son résultat. */
function pushOp(string $type, array $payload): array
{
    $token = test()->adminUser->createToken('mobile')->plainTextToken;

    return test()->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) test()->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => $type,
                'payload' => $payload,
            ]],
        ])->assertOk()->json('results.0');
}

test('un agent PRÊTÉ peut être pointé depuis le mobile', function () {
    // Le cas exact du terrain : l'employé est descendu au téléphone parce que son
    // compte a accès à cette ferme, mais son dossier vit sur l'autre site.
    $otherFarm = Farm::firstOrCreate(['code' => 'KER-001'], ['name' => 'Kérouané', 'is_active' => true]);

    $lentAccount = User::factory()->create(['role_id' => $this->adminUser->role_id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $lentAccount->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $lent = Employee::factory()->create([
        'farm_id' => $otherFarm->id,      // dossier à Kérouané
        'user_id' => $lentAccount->id,    // compte ayant accès à Kindia
        'status'  => 'Actif',
    ]);

    // Il figure bien dans ce que le mobile reçoit…
    expect(Employee::assignableInCurrentFarm()->pluck('id'))->toContain($lent->id);

    // …et le push l'accepte désormais.
    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'attendance.create',
                'payload' => [
                    'uuid' => (string) Str::uuid(),
                    'attendance_date' => today()->toDateString(),
                    'rows' => [
                        ['employee_id' => $lent->id, 'status' => 'present'],
                    ],
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->not->toBe('validation_failed');
});

test('un employé d’une AUTRE ferme sans accès reste refusé', function () {
    // Le garde-fou : élargir la validation ne doit pas ouvrir tous les sites.
    $otherFarm = Farm::firstOrCreate(['code' => 'KER-002'], ['name' => 'Autre site', 'is_active' => true]);
    $stranger = Employee::factory()->create(['farm_id' => $otherFarm->id, 'user_id' => null, 'status' => 'Actif']);

    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'attendance.create',
                'payload' => [
                    'uuid' => (string) Str::uuid(),
                    'attendance_date' => today()->toDateString(),
                    'rows' => [['employee_id' => $stranger->id, 'status' => 'present']],
                ],
            ]],
        ])->assertOk();

    // Refusé, et refusé SUR LE BON CHAMP : un test négatif qui passe pour une
    // autre raison ne prouve rien.
    expect($response->json('results.0.status'))->toBe('validation_failed')
        ->and($response->json('results.0.errors'))->toHaveKey('rows.0.employee_id');
});

test('un dossier ARCHIVÉ ne revient pas par la validation élargie', function () {
    $archived = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'status' => 'Actif',
    ]);
    $archived->delete();   // archivage (SoftDeletes)

    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'attendance.create',
                'payload' => [
                    'uuid' => (string) Str::uuid(),
                    'attendance_date' => today()->toDateString(),
                    'rows' => [['employee_id' => $archived->id, 'status' => 'present']],
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->toBe('validation_failed')
        ->and($response->json('results.0.errors'))->toHaveKey('rows.0.employee_id');
});

test('une avance d’horloge de quelques minutes ne condamne plus la saisie', function () {
    // Le cas du terrain : le téléphone est en avance, la tâche part au bac.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id, 'status' => 'Actif',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'title' => 'Plantation rejets + fumure de fond', 'category' => 'semis',
        'scheduled_date' => today()->toDateString(), 'status' => 'a_faire',
    ]);

    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'task.complete',
                'payload' => [
                    'uuid'    => (string) Str::uuid(),
                    'task_id' => $task->id,
                    'done_at' => now()->addMinutes(3)->toIso8601String(),
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->not->toBe('validation_failed');

    // L'horodatage est RAMENÉ à l'instant serveur : pas d'acte daté dans le futur
    // au registre, mais pas de saisie perdue non plus.
    $task->refresh();
    expect($task->status)->toBe('fait')
        ->and($task->completed_at->isFuture())->toBeFalse();
});

test('dater une tâche la semaine prochaine reste refusé', function () {
    // Une dérive d'horloge se compte en minutes : au-delà, c'est une saisie fausse.
    $employee = Employee::factory()->create([
        'farm_id' => $this->farm->id, 'user_id' => $this->adminUser->id, 'status' => 'Actif',
    ]);

    $task = TaskAssignment::create([
        'farm_id' => $this->farm->id, 'employee_id' => $employee->id,
        'title' => 'Tâche datée du futur', 'category' => 'semis',
        'scheduled_date' => today()->toDateString(), 'status' => 'a_faire',
    ]);

    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Farm-Id', (string) $this->farm->id)
        ->postJson('/api/v1/sync/push', [
            'operations' => [[
                'op_uuid' => (string) Str::uuid(),
                'type'    => 'task.complete',
                'payload' => [
                    'uuid'    => (string) Str::uuid(),
                    'task_id' => $task->id,
                    'done_at' => now()->addWeek()->toIso8601String(),
                ],
            ]],
        ])->assertOk();

    expect($response->json('results.0.status'))->toBe('validation_failed')
        ->and($response->json('results.0.errors'))->toHaveKey('done_at');
});

test('le téléphone corrige sa dérive sur l’horloge du serveur', function () {
    // Corriger la CAUSE, pas seulement en tolérer l'effet : le serveur est déjà
    // l'autorité de temps pour le « since » de synchronisation.
    $sync = file_get_contents(base_path('mobile/src/offline/sync.ts'));

    expect($sync)->toContain('serverClockOffsetMs')
        ->and($sync)->toContain('noteServerTime(response.server_time)')
        ->and($sync)->toContain('new Date(Date.now() + serverClockOffsetMs)');
});

test('une saisie refusée peut être RETENTÉE, pas seulement abandonnée', function () {
    // Le motif du refus peut avoir disparu — règle corrigée, employé rattaché,
    // horloge résorbée. Le seul geste offert était d'abandonner, donc de jeter un
    // travail de terrain déjà saisi.
    $sync = file_get_contents(base_path('mobile/src/offline/sync.ts'));
    expect($sync)->toContain('export async function retryOperation');

    $screen = file_get_contents(base_path('mobile/src/features/mon-espace/MonEspaceScreen.tsx'));
    expect($screen)->toContain('retryOperation')
        ->and($screen)->toContain("t('Réessayer')")
        // Et l'abandon reste possible : on n'enlève rien.
        ->and($screen)->toContain("t('Abandonner cette saisie')");
});

test('la validation employé est UNE règle, partagée par toutes les opérations', function () {
    // Quatre opérations référencent un employé : elles doivent toutes accepter
    // exactement ce que le mobile a reçu.
    $service = file_get_contents(app_path('Services/Sync/SyncService.php'));

    expect(substr_count($service, "farmScopedExists('employees')"))->toBe(0)
        ->and(substr_count($service, '$this->employeeExists()'))->toBe(4);
});
