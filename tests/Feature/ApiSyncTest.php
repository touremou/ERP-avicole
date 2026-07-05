<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * API v1 — /sync/push + /sync/pull (fusion audit A2) et contexte ferme
 * (middleware farm.api). Vérifie : idempotence uuid, conflits métier,
 * permissions par module, indépendance des opérations d'un même lot,
 * delta pull avec tombstones, et l'ÉTANCHÉITÉ MULTI-FERMES de l'API.
 */

beforeEach(function () {
    $this->farmA = Farm::firstOrCreate(['code' => 'FA-001'], ['name' => 'Ferme A', 'is_active' => true]);
    $this->farmB = Farm::firstOrCreate(['code' => 'FB-001'], ['name' => 'Ferme B', 'is_active' => true]);

    // Matrice Modules × Rôles (source unique des Gates).
    $makeRole = function (string $name, array $perms) {
        $role = Role::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
        );

        $now = now();
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $role;
    };

    $manager = $makeRole('manager', ['L', 'C', 'M']);
    $viewer  = $makeRole('viewer', ['L']);

    $this->manager = User::factory()->create(['role_id' => $manager->id]);
    $this->viewer  = User::factory()->create(['role_id' => $viewer->id]);

    // Affectation ferme : les deux utilisateurs appartiennent à la ferme A.
    foreach ([$this->manager, $this->viewer] as $user) {
        DB::table('farm_user')->insert([
            'farm_id'    => $this->farmA->id,
            'user_id'    => $user->id,
            'is_default' => true,
            'is_owner'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Données ferme A (contexte factories = session, comme le web).
    session(['current_farm_id' => $this->farmA->id]);
    $this->building = Building::factory()->create(['type' => 'chair']);
    $this->batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);
});

function pushOps(array $operations): array
{
    return ['operations' => array_map(fn ($op) => [
        'op_uuid' => $op['op_uuid'] ?? (string) Str::uuid(),
        'type'    => $op['type'],
        'payload' => $op['payload'],
    ], $operations)];
}

// ─── AUTH & ÉTANCHÉITÉ ───

test('push exige un token Sanctum', function () {
    $this->postJson('/api/v1/sync/push', pushOps([[
        'type'    => 'expense.create',
        'payload' => [],
    ]]))->assertStatus(401);
});

test("l'API est bornée à la ferme de l'utilisateur (étanchéité multi-sites)", function () {
    // Un lot dans la ferme B, créé hors du périmètre de l'utilisateur.
    session(['current_farm_id' => $this->farmB->id]);
    $batchB = Batch::factory()->create([
        'building_id'      => Building::factory()->create(['type' => 'chair'])->id,
        'status'           => 'Actif',
        'current_quantity' => 100,
    ]);
    session(['current_farm_id' => null]);

    Sanctum::actingAs($this->manager);

    // Sans en-tête : résolution ferme par défaut (A) → le lot B est invisible.
    $codes = collect($this->getJson('/api/v1/batches')->assertOk()->json('data'))->pluck('code');
    expect($codes)->toContain($this->batch->code)
        ->not->toContain($batchB->code);

    // En-tête X-Farm-Id vers une ferme non affectée → refus explicite.
    $this->withHeader('X-Farm-Id', (string) $this->farmB->id)
        ->getJson('/api/v1/batches')
        ->assertStatus(403);
});

// ─── POINTAGE : succès, idempotence, conflit, permission ───

test('daily_check.create : succès, effet observer, uuid persisté', function () {
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();

    $response = $this->postJson('/api/v1/sync/push', pushOps([[
        'type'    => 'daily_check.create',
        'payload' => [
            'uuid'       => $uuid,
            'batch_id'   => $this->batch->id,
            'check_date' => '2026-07-01',
            'mortality'  => 3,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('success');
    expect($response->json('server_time'))->not->toBeNull();

    $check = DailyCheck::withoutGlobalScopes()->where('uuid', $uuid)->first();
    expect($check)->not->toBeNull();                       // uuid réellement persisté
    expect($this->batch->fresh()->current_quantity)->toBe(497); // observer effectif
});

test('daily_check.create : le rejeu du même uuid renvoie already_synced sans double comptage', function () {
    Sanctum::actingAs($this->manager);

    $op = pushOps([[
        'type'    => 'daily_check.create',
        'payload' => [
            'uuid'       => (string) Str::uuid(),
            'batch_id'   => $this->batch->id,
            'check_date' => '2026-07-01',
            'mortality'  => 3,
        ],
    ]]);

    $this->postJson('/api/v1/sync/push', $op)->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', $op)->assertOk();

    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(DailyCheck::where('batch_id', $this->batch->id)->count())->toBe(1);
    expect($this->batch->fresh()->current_quantity)->toBe(497); // pas re-décrémenté
});

test('daily_check.create : un jour déjà pointé (autre uuid) renvoie conflict', function () {
    Sanctum::actingAs($this->manager);

    $payload = [
        'batch_id'   => $this->batch->id,
        'check_date' => '2026-07-01',
        'mortality'  => 1,
    ];

    $this->postJson('/api/v1/sync/push', pushOps([[
        'type' => 'daily_check.create',
        'payload' => $payload + ['uuid' => (string) Str::uuid()],
    ]]))->assertOk();

    $second = $this->postJson('/api/v1/sync/push', pushOps([[
        'type' => 'daily_check.create',
        'payload' => $payload + ['uuid' => (string) Str::uuid()],
    ]]))->assertOk();

    expect($second->json('results.0.status'))->toBe('conflict');
});

test('daily_check.create : un rôle lecture seule est refusé (permission_denied)', function () {
    Sanctum::actingAs($this->viewer);

    $response = $this->postJson('/api/v1/sync/push', pushOps([[
        'type'    => 'daily_check.create',
        'payload' => [
            'uuid'       => (string) Str::uuid(),
            'batch_id'   => $this->batch->id,
            'check_date' => '2026-07-01',
            'mortality'  => 1,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('permission_denied');
    expect(DailyCheck::count())->toBe(0);
});

// ─── LOT D'OPÉRATIONS : indépendance + stock ───

test('push traite chaque opération indépendamment (une invalide ne bloque pas les autres)', function () {
    Sanctum::actingAs($this->manager);

    $stock = Stock::factory()->create([
        'category'         => 'oeufs',
        'item_name'        => 'Œufs calibre L',
        'unit'             => 'Plateau',
        'current_quantity' => 10,
    ]);

    $expenseUuid = (string) Str::uuid();

    $response = $this->postJson('/api/v1/sync/push', pushOps([
        [
            'type'    => 'expense.create',
            'payload' => [
                'uuid'         => $expenseUuid,
                'category'     => 'fournitures',
                'label'        => 'Achat gants terrain',
                'amount'       => 50000,
                'expense_date' => '2026-07-01',
            ],
        ],
        [
            'type'    => 'type.inconnu',
            'payload' => ['n_importe' => 'quoi'],
        ],
        [
            'type'    => 'stock_movement.create',
            'payload' => [
                'uuid'     => (string) Str::uuid(),
                'stock_id' => $stock->id,
                'type'     => 'out',
                'quantity' => 25, // > disponible → conflit, pas 500
            ],
        ],
    ]))->assertOk();

    $statuses = collect($response->json('results'))->pluck('status');
    expect($statuses->all())->toBe(['success', 'validation_failed', 'conflict']);

    // La dépense est bien créée EN ATTENTE (validation P&L en ligne).
    $expense = Expense::withoutGlobalScopes()->where('uuid', $expenseUuid)->first();
    expect($expense)->not->toBeNull();
    expect($expense->status)->toBe('en_attente');
    expect($stock->fresh()->current_quantity)->toEqual(10); // sortie refusée
});

test('sale.create : vente créée en brouillon, rejeu idempotent', function () {
    Sanctum::actingAs($this->manager);

    $client = [
        'client_id'  => 'CLI-0001',
        'name'       => 'Client Terrain',
        'type'       => 'particulier',
        'category'   => 'detaillant',
        'status'     => 'actif',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if (Illuminate\Support\Facades\Schema::hasColumn('clients', 'farm_id')) {
        $client['farm_id'] = $this->farmA->id;
    }
    $clientId = DB::table('clients')->insertGetId($client);

    $op = pushOps([[
        'type'    => 'sale.create',
        'payload' => [
            'uuid'      => (string) Str::uuid(),
            'client_id' => $clientId,
            'sale_date' => '2026-07-01',
            'type'      => 'bon_livraison',
            'items'     => [[
                'product_type' => 'oeufs',
                'product_name' => 'Œufs calibre L',
                'quantity'     => 5,
                'unit'         => 'Plateau',
                'unit_price'   => 45000,
            ]],
        ],
    ]]);

    $first = $this->postJson('/api/v1/sync/push', $op)->assertOk();
    expect($first->json('results.0.status'))->toBe('success');
    expect($first->json('results.0.reference'))->not->toBeNull();

    $sale = Sale::withoutGlobalScopes()->where('reference', $first->json('results.0.reference'))->first();
    expect($sale->status)->toBe('brouillon'); // jamais auto-validée hors ligne

    $replay = $this->postJson('/api/v1/sync/push', $op)->assertOk();
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(Sale::withoutGlobalScopes()->count())->toBe(1);
});

// ─── LOT (batch.upsert) : gate module réel + LWW ───

test('batch.upsert : autorisé par elevage.C (gate aligné sur le module réel) et conflit LWW', function () {
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();

    $payload = [
        'uuid'             => $uuid,
        'code'             => 'SYNC-001',
        'type'             => 'chair',
        'building_id'      => $this->building->id,
        'initial_quantity' => 100,
        'current_quantity' => 100,
        'arrival_date'     => '2026-06-20',
        'updated_at'       => now()->toIso8601String(),
    ];

    $create = $this->postJson('/api/v1/sync/push', pushOps([[
        'type' => 'batch.upsert', 'payload' => $payload,
    ]]))->assertOk();

    expect($create->json('results.0.status'))->toBe('success');
    expect(Batch::withoutGlobalScopes()->where('uuid', $uuid)->exists())->toBeTrue();

    // Le serveur avance ; le terrain renvoie une version PLUS ANCIENNE → conflit
    // Last-Write-Wins, avec la version serveur en retour.
    $stale = $this->postJson('/api/v1/sync/push', pushOps([[
        'type'    => 'batch.upsert',
        'payload' => array_merge($payload, [
            'current_quantity' => 42,
            'updated_at'       => now()->subDay()->toIso8601String(),
        ]),
    ]]))->assertOk();

    expect($stale->json('results.0.status'))->toBe('conflict');
    expect($stale->json('results.0.data.code'))->toBe('SYNC-001');
    expect(Batch::withoutGlobalScopes()->where('uuid', $uuid)->value('current_quantity'))->toBe(100);
});

// ─── PULL : delta + tombstones, borné à la ferme ───

test('pull renvoie les données de référence de la ferme, les tombstones, et respecte since', function () {
    Sanctum::actingAs($this->manager);

    // Un lot supprimé (soft delete) → doit apparaître en tombstone.
    $deleted = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 10,
    ]);
    $deletedId = $deleted->id;
    $deleted->delete();

    // Un lot d'une AUTRE ferme → jamais exposé.
    session(['current_farm_id' => $this->farmB->id]);
    $batchB = Batch::factory()->create([
        'building_id'      => Building::factory()->create(['type' => 'chair'])->id,
        'current_quantity' => 5,
    ]);
    session(['current_farm_id' => null]);

    $response = $this->getJson('/api/v1/sync/pull')->assertOk();

    $codes = collect($response->json('entities.batches.upserts'))->pluck('code');
    expect($codes)->toContain($this->batch->code)
        ->not->toContain($batchB->code);

    expect($response->json('entities.batches.deletes'))->toContain($deletedId);
    expect($response->json('entities'))->toHaveKeys(['batches', 'buildings', 'stocks', 'clients', 'products']);
    expect($response->json('server_time'))->not->toBeNull();

    // Delta : rien de plus récent que « maintenant + 1 min ».
    $empty = $this->getJson('/api/v1/sync/pull?since=' . urlencode(now()->addMinute()->toIso8601String()))
        ->assertOk();
    expect($empty->json('entities.batches.upserts'))->toBe([]);
    expect($empty->json('entities.batches.deletes'))->toBe([]);
});

test("daily_check.create : stock aliment insuffisant renvoie conflict (non rejouable), pas error", function () {
    // Aucun stock d'aliment seedé : consommer 25 kg doit être refusé par la
    // règle métier (RecordDailyCheck::checkFeedStock). Le point critique :
    // le statut doit être 'conflict' (le terrain sort l'op de sa file vers le
    // bac « À corriger ») et non 'error' (qui serait retenté indéfiniment).
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();
    $response = $this->postJson('/api/v1/sync/push', pushOps([[
        'type'    => 'daily_check.create',
        'payload' => [
            'uuid'          => $uuid,
            'batch_id'      => $this->batch->id,
            'check_date'    => now()->toDateString(),
            'mortality'     => 1,
            'feed_consumed' => 25,
            'feed_type'     => 'Aliment Inexistant',
        ],
    ]]));

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('conflict');
    expect($response->json('results.0.message'))->toContain('Stock insuffisant');
    expect(App\Models\DailyCheck::count())->toBe(0);
});

test('health_incident.create : incident créé avec photo, idempotent au rejeu', function () {
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();
    $op = [[
        'type'    => 'health_incident.create',
        'payload' => [
            'uuid'            => $uuid,
            'batch_id'        => $this->batch->id,
            'incident_date'   => now()->toDateString(),
            'mortality_count' => 4,
            'symptoms'        => 'Fientes vertes, prostration',
            'severity'        => 'critique',
            'photo_path'      => 'field/incident/autopsie.jpg',
        ],
    ]];

    $response = $this->postJson('/api/v1/sync/push', pushOps($op))->assertOk();
    expect($response->json('results.0.status'))->toBe('success');

    $incident = App\Models\HealthIncident::where('uuid', $uuid)->first();
    expect($incident)->not->toBeNull();
    expect($incident->building_id)->toBe($this->batch->building_id); // dérivé du lot
    expect($incident->severity)->toBe('critique');
    expect($incident->photo_path)->toBe('field/incident/autopsie.jpg');
    expect($incident->status)->toBe(App\Models\HealthIncident::STATUS_PENDING);

    // Rejeu réseau : même uuid → already_synced, pas de doublon.
    $replay = $this->postJson('/api/v1/sync/push', pushOps($op));
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(App\Models\HealthIncident::count())->toBe(1);
});

test('health_incident.create : refusé pour un rôle lecture seule', function () {
    Sanctum::actingAs($this->viewer);

    $uuid = (string) Str::uuid();
    $response = $this->postJson('/api/v1/sync/push', pushOps([[
        'type'    => 'health_incident.create',
        'payload' => ['uuid' => $uuid, 'batch_id' => $this->batch->id, 'incident_date' => now()->toDateString(), 'mortality_count' => 1, 'symptoms' => 'x'],
    ]]));

    expect($response->json('results.0.status'))->toBe('permission_denied');
    expect(App\Models\HealthIncident::count())->toBe(0);
});

test('pull expose production_types et production_type_id sur les lots', function () {
    Sanctum::actingAs($this->manager);

    $response = $this->getJson('/api/v1/sync/pull')->assertOk();
    expect($response->json('entities.production_types.upserts'))->not->toBeEmpty();
    expect($response->json('entities.batches.upserts.0'))->toHaveKey('production_type_id');
});

test('téléversement de photo terrain : stockée sur le disque public', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    Sanctum::actingAs($this->manager);

    $response = $this->postJson('/api/v1/photos', [
        'photo'   => Illuminate\Http\UploadedFile::fake()->image('autopsie.jpg', 800, 600),
        'context' => 'incident',
    ]);

    $response->assertCreated()->assertJsonStructure(['path', 'url', 'server_time']);
    Illuminate\Support\Facades\Storage::disk('public')->assertExists($response->json('path'));
    expect($response->json('path'))->toStartWith('field/incident/');
});

test('téléversement de photo refusé en lecture seule', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    Sanctum::actingAs($this->viewer);

    $this->postJson('/api/v1/photos', [
        'photo' => Illuminate\Http\UploadedFile::fake()->image('x.jpg'),
    ])->assertForbidden();
});
