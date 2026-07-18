<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\CcpRecord;
use App\Models\CleaningLog;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Provider;
use App\Models\Role;
use App\Models\SlaughterOrder;
use App\Models\SlaughterReception;
use App\Models\TemperatureLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Cœur sanitaire HACCP (spec Transformation E1/E3/E4/E7) via /sync/push :
 *   slaughter_reception.create / ccp_record.create /
 *   temperature_log.create / cleaning_log.create
 *
 * Invariants testés :
 *  - conformité calculée SERVEUR selon les seuils Settings (jamais confiée
 *    au client) ;
 *  - RG-02 : CCP non conforme rattaché à un ordre → blocage automatique ;
 *  - RG-04 : réception refusée → motif obligatoire, alerte, aucune reprise ;
 *  - RG-06 : registres insert-only, idempotence uuid ;
 *  - action corrective exigée en face de tout constat non conforme ;
 *  - double horodatage releve_at (client) / synced_at (serveur).
 */

beforeEach(function () {
    $this->farmA = Farm::firstOrCreate(['code' => 'FA-001'], ['name' => 'Ferme A', 'is_active' => true]);

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

    session(['current_farm_id' => $this->farmA->id]);
});

function haccpOps(array $operations): array
{
    return ['operations' => array_map(fn ($op) => [
        'op_uuid' => $op['op_uuid'] ?? (string) Str::uuid(),
        'type'    => $op['type'],
        'payload' => $op['payload'],
    ], $operations)];
}

function makeHaccpOrder(User $requester): SlaughterOrder
{
    $batch = Batch::factory()->create([
        'building_id'      => Building::factory()->create(['type' => 'chair'])->id,
        'status'           => 'Actif',
        'initial_quantity' => 100,
        'current_quantity' => 100,
    ]);

    return SlaughterOrder::create([
        'order_number'     => SlaughterOrder::generateNumber(),
        'batch_id'         => $batch->id,
        'planned_date'     => now()->toDateString(),
        'planned_quantity' => 60,
        'status'           => 'planifie',
        'requested_by'     => $requester->id,
    ]);
}

// ─── RÉCEPTION DU VIF (CCP 1) ───

test('slaughter_reception.create enregistre une réception acceptée, immuable et double-horodatée', function () {
    $provider = Provider::factory()->create();
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();
    $releveAt = now()->subHours(6)->toIso8601String(); // saisie terrain du matin

    $response = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'slaughter_reception.create',
        'payload' => [
            'uuid'                 => $uuid,
            'provider_id'          => $provider->id,
            'reception_date'       => now()->toDateString(),
            'announced_quantity'   => 120,
            'received_quantity'    => 118,
            'rejected_quantity'    => 3,
            'total_live_weight_kg' => 216.5,
            'sanitary_state'       => 'conforme',
            'fasting_respected'    => 'oui',
            'decision'             => 'accepte',
            'releve_at'            => $releveAt,
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('success');

    $reception = SlaughterReception::withoutGlobalScopes()->where('uuid', $uuid)->first();
    expect($reception)->not->toBeNull()
        ->and($reception->validated_at)->not->toBeNull()          // immuable dès la pose
        ->and($reception->synced_at)->not->toBeNull()             // horodatage serveur
        ->and($reception->releve_at->toIso8601String())->toBe($releveAt) // horodatage client conservé
        ->and($reception->controller_id)->toBe($this->manager->id);
});

test('une réception refusée exige un motif (validation_failed sans lui)', function () {
    $provider = Provider::factory()->create();
    Sanctum::actingAs($this->manager);

    $payload = fn (?string $reason) => [
        'type'    => 'slaughter_reception.create',
        'payload' => array_filter([
            'uuid'                 => (string) Str::uuid(),
            'provider_id'          => $provider->id,
            'reception_date'       => now()->toDateString(),
            'received_quantity'    => 50,
            'total_live_weight_kg' => 90,
            'sanitary_state'       => 'non_conforme',
            'fasting_respected'    => 'non',
            'decision'             => 'refuse',
            'decision_reason'      => $reason,
        ]),
    ];

    $sans = $this->postJson('/api/v1/sync/push', haccpOps([$payload(null)]))->assertOk();
    expect($sans->json('results.0.status'))->toBe('validation_failed')
        ->and($sans->json('results.0.errors'))->toHaveKey('decision_reason');

    $avec = $this->postJson('/api/v1/sync/push', haccpOps([$payload('Sujets fiévreux, plumage anormal')]))->assertOk();
    expect($avec->json('results.0.status'))->toBe('success');
});

test('une réception SANS écarté (rejected_quantity null) réussit — pas de 500', function () {
    // Régression « Erreur interne lors de la réconciliation » : le client
    // envoyait null pour 0 écarté, violant la contrainte NOT NULL. Le garde-fou
    // modèle coalesce à 0.
    $provider = Provider::factory()->create();
    Sanctum::actingAs($this->manager);

    $uuid = (string) Str::uuid();
    $res = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'slaughter_reception.create',
        'payload' => [
            'uuid'                 => $uuid,
            'provider_id'          => $provider->id,
            'reception_date'       => now()->toDateString(),
            'received_quantity'    => 20,
            'rejected_quantity'    => null,          // 0 écarté → null envoyé par le terrain
            'total_live_weight_kg' => 40,
            'sanitary_state'       => 'conforme',
            'fasting_respected'    => 'oui',
            'decision'             => 'accepte',
        ],
    ]]))->assertOk();

    expect($res->json('results.0.status'))->toBe('success');
    expect((int) SlaughterReception::withoutGlobalScopes()->where('uuid', $uuid)->value('rejected_quantity'))->toBe(0);
});

// ─── CCP : CONFORMITÉ SERVEUR + BLOCAGE AUTOMATIQUE (RG-02) ───

test('ccp3 hors seuil → conformité recalculée serveur, ordre bloqué automatiquement', function () {
    $order = makeHaccpOrder($this->manager);
    Sanctum::actingAs($this->manager);

    // Le client prétend conforme, mais 6.2 °C > seuil (4 °C) : le serveur tranche.
    $response = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'ccp_record.create',
        'payload' => [
            'uuid'               => (string) Str::uuid(),
            'ccp'                => 'ccp3_refroidissement',
            'slaughter_order_id' => $order->id,
            'mesures'            => ['temperature_coeur' => 6.2],
            'conforme'           => true,
            'corrective_action'  => 'Carcasses replongées en bac glacé, re-contrôle dans 30 min',
            'releve_at'          => now()->toIso8601String(),
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('success')
        ->and($response->json('results.0.conforme'))->toBeFalse();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe('bloque')
        ->and($fresh->blocked_reason)->toContain('CCP 3')
        ->and($fresh->blocked_at)->not->toBeNull();

    // RG-03 : l'exécution d'un ordre bloqué est refusée par le service.
    $exec = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'slaughter.execute',
        'payload' => [
            'uuid'                    => (string) Str::uuid(),
            'slaughter_order_id'      => $order->id,
            'execution_date'          => now()->toDateString(),
            'actual_quantity'         => 60,
            'total_live_weight_kg'    => 120,
            'total_carcass_weight_kg' => 90,
        ],
    ]]))->assertOk();
    expect($exec->json('results.0.status'))->toBe('conflict');
});

test('ccp non conforme SANS action corrective → conflict, rien n\'est écrit', function () {
    $order = makeHaccpOrder($this->manager);
    Sanctum::actingAs($this->manager);

    $response = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'ccp_record.create',
        'payload' => [
            'uuid'               => (string) Str::uuid(),
            'ccp'                => 'ccp3_refroidissement',
            'slaughter_order_id' => $order->id,
            'mesures'            => ['temperature_coeur' => 7.0],
            'releve_at'          => now()->toIso8601String(),
        ],
    ]]))->assertOk();

    expect($response->json('results.0.status'))->toBe('conflict')
        ->and($response->json('results.0.message'))->toContain('action corrective');
    expect(CcpRecord::withoutGlobalScopes()->count())->toBe(0);
    expect($order->fresh()->status)->toBe('planifie'); // pas de blocage sans écriture
});

test('ccp3 dans le seuil → conforme, ordre intact, idempotent au rejeu', function () {
    $order = makeHaccpOrder($this->manager);
    Sanctum::actingAs($this->manager);

    $payload = [
        'uuid'               => (string) Str::uuid(),
        'ccp'                => 'ccp3_refroidissement',
        'slaughter_order_id' => $order->id,
        'mesures'            => ['temperature_coeur' => 3.4],
        'releve_at'          => now()->toIso8601String(),
    ];

    $first = $this->postJson('/api/v1/sync/push', haccpOps([['type' => 'ccp_record.create', 'payload' => $payload]]))->assertOk();
    expect($first->json('results.0.status'))->toBe('success')
        ->and($first->json('results.0.conforme'))->toBeTrue();
    expect($order->fresh()->status)->toBe('planifie');

    $replay = $this->postJson('/api/v1/sync/push', haccpOps([['type' => 'ccp_record.create', 'payload' => $payload]]))->assertOk();
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(CcpRecord::withoutGlobalScopes()->count())->toBe(1);
});

test('ccp2 : le taux de carcasses souillées est comparé au seuil paramétré', function () {
    $order = makeHaccpOrder($this->manager);
    Sanctum::actingAs($this->manager);

    // 5/60 = 8,3 % > 2 % (abattoir.ccp2_soiled_max_pct) → non conforme.
    $response = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'ccp_record.create',
        'payload' => [
            'uuid'               => (string) Str::uuid(),
            'ccp'                => 'ccp2_evisceration',
            'slaughter_order_id' => $order->id,
            'mesures'            => ['carcasses_total' => 60, 'carcasses_souillees' => 5],
            'corrective_action'  => 'Ralentissement cadence, rinçage renforcé, écartées détruites',
            'releve_at'          => now()->toIso8601String(),
        ],
    ]]))->assertOk();

    expect($response->json('results.0.conforme'))->toBeFalse();
    expect($order->fresh()->status)->toBe('bloque');
});

// ─── REGISTRE DES TEMPÉRATURES (E4) ───

test('temperature_log.create : conformité serveur selon le point et les seuils', function () {
    Sanctum::actingAs($this->manager);

    $push = fn (string $point, float $temp, ?string $action = null) => $this->postJson(
        '/api/v1/sync/push',
        haccpOps([[
            'type'    => 'temperature_log.create',
            'payload' => array_filter([
                'uuid'              => (string) Str::uuid(),
                'point'             => $point,
                'equipment_ref'     => 'CF-01',
                'temperature'       => $temp,
                'corrective_action' => $action,
                'releve_at'         => now()->toIso8601String(),
            ], fn ($v) => $v !== null),
        ]])
    )->assertOk();

    // 3,5 °C en chambre froide positive (0–4) → conforme.
    expect($push('chambre_froide_positive', 3.5)->json('results.0.conforme'))->toBeTrue();
    // 6,8 °C → hors seuil.
    expect($push('chambre_froide_positive', 6.8, 'Groupe froid vérifié, porte refermée')->json('results.0.conforme'))->toBeFalse();
    // -20 °C en congélation (max -18) → conforme.
    expect($push('congelation', -20)->json('results.0.conforme'))->toBeTrue();
    // -12 °C → hors seuil.
    expect($push('congelation', -12, 'Transfert vers CF-02, maintenance appelée')->json('results.0.conforme'))->toBeFalse();

    expect(TemperatureLog::withoutGlobalScopes()->count())->toBe(4)
        ->and(TemperatureLog::withoutGlobalScopes()->where('conforme', false)->count())->toBe(2);
});

// ─── REGISTRE NETTOYAGE (E7) ───

test('cleaning_log.create enregistre le nettoyage, idempotent, viewer refusé', function () {
    Sanctum::actingAs($this->manager);

    $payload = [
        'uuid'         => (string) Str::uuid(),
        'zone'         => 'surfaces_tables',
        'product_used' => 'Détergent alcalin agréé + désinfectant',
        'dosage'       => '2 %',
        'done_at'      => now()->toIso8601String(),
    ];

    $first = $this->postJson('/api/v1/sync/push', haccpOps([['type' => 'cleaning_log.create', 'payload' => $payload]]))->assertOk();
    expect($first->json('results.0.status'))->toBe('success');

    $replay = $this->postJson('/api/v1/sync/push', haccpOps([['type' => 'cleaning_log.create', 'payload' => $payload]]))->assertOk();
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(CleaningLog::withoutGlobalScopes()->count())->toBe(1);

    Sanctum::actingAs($this->viewer);
    $denied = $this->postJson('/api/v1/sync/push', haccpOps([[
        'type'    => 'cleaning_log.create',
        'payload' => array_merge($payload, ['uuid' => (string) Str::uuid()]),
    ]]))->assertOk();
    expect($denied->json('results.0.status'))->toBe('permission_denied');
});

// ─── SOUS-PRODUITS (E9) ───

test('byproduct.create enregistre le sous-produit, idempotent', function () {
    Sanctum::actingAs($this->manager);

    $payload = [
        'uuid'         => (string) Str::uuid(),
        'type'         => 'plumes',
        'quantity_kg'  => 18.5,
        'destination'  => 'compost',
        'collected_at' => now()->toIso8601String(),
    ];

    $first = $this->postJson('/api/v1/sync/push', haccpOps([['type' => 'byproduct.create', 'payload' => $payload]]))->assertOk();
    expect($first->json('results.0.status'))->toBe('success');

    $replay = $this->postJson('/api/v1/sync/push', haccpOps([['type' => 'byproduct.create', 'payload' => $payload]]))->assertOk();
    expect($replay->json('results.0.status'))->toBe('already_synced');
    expect(\App\Models\SlaughterByproduct::withoutGlobalScopes()->count())->toBe(1);

    $byproduct = \App\Models\SlaughterByproduct::withoutGlobalScopes()->first();
    expect((float) $byproduct->quantity_kg)->toBe(18.5)
        ->and($byproduct->destination)->toBe('compost');
});

// ─── COMMANDE PLANIFIÉE §9 : complétude des registres ───

test('haccp:check-registers alerte sur relevés manquants et CCP 3 absent', function () {
    // Un abattage exécuté aujourd'hui sans CCP 3, zéro relevé température.
    $order = makeHaccpOrder($this->manager);
    $order->update(['status' => 'termine', 'actual_date' => now()->toDateString()]);

    $this->artisan('haccp:check-registers')
        ->expectsOutputToContain('2 alerte(s)')
        ->assertExitCode(0);

    // Registres complets → plus d'alerte.
    session(['current_farm_id' => $this->farmA->id]);
    app(\App\Actions\Slaughter\RecordTemperatureLog::class)->execute([
        'point' => 'chambre_froide_positive', 'temperature' => 3.0,
        'operator_id' => $this->manager->id, 'releve_at' => now(),
    ]);
    app(\App\Actions\Slaughter\RecordTemperatureLog::class)->execute([
        'point' => 'congelation', 'temperature' => -19.0,
        'operator_id' => $this->manager->id, 'releve_at' => now(),
    ]);
    app(\App\Actions\Slaughter\RecordCcp::class)->execute([
        'ccp' => \App\Models\CcpRecord::CCP3, 'slaughter_order_id' => $order->id,
        'mesures' => ['temperature_coeur' => 3.1],
        'operator_id' => $this->manager->id, 'releve_at' => now(),
    ]);

    $this->artisan('haccp:check-registers')
        ->expectsOutputToContain('0 alerte(s)')
        ->assertExitCode(0);
});

// ─── PULL : éleveurs livreurs ───

test('pull expose les providers (liste blanche sans données sensibles)', function () {
    $provider = Provider::factory()->create();
    Sanctum::actingAs($this->manager);

    $response = $this->getJson('/api/v1/sync/pull')->assertOk();
    $pulled = collect($response->json('entities.providers.upserts'))->firstWhere('id', $provider->id);

    expect($pulled)->not->toBeNull()
        ->and($pulled)->toHaveKeys(['id', 'name', 'type', 'status'])
        ->and($pulled)->not->toHaveKey('phone')
        ->and($pulled)->not->toHaveKey('payment_terms');
});
