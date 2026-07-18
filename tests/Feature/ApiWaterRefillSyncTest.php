<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * water_refill.create — ravitaillement d'une citerne saisi hors-ligne puis
 * poussé : niveau relevé, événement tracé, idempotent, cloisonné (ressources.C).
 */

function refillRole(string $name, array $levels): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    $mod = Module::where('slug', 'ressources')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $levels), 'can_create' => in_array('C', $levels),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-WR'], ['name' => 'Ferme Eau', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => refillRole('agent_eau', ['L', 'C'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);

    $this->citerne = WaterSource::create([
        'farm_id' => $this->farm->id, 'name' => 'Citerne A', 'type' => 'citerne',
        'capacity_liters' => 10000, 'current_level_liters' => 2000, 'current_level_percent' => 20, 'is_active' => true,
    ]);
});

function refillOp(int $sourceId, float $volume, ?string $uuid = null): array
{
    return [
        'op_uuid' => Str::uuid()->toString(),
        'type'    => 'water_refill.create',
        'payload' => [
            'uuid'                => $uuid ?? Str::uuid()->toString(),
            'water_source_id'     => $sourceId,
            'volume_added_liters' => $volume,
            'refill_date'         => now()->toDateString(),
            'cost'                => 15000,
            'notes'               => 'Camion-citerne',
        ],
    ];
}

test('water_refill.create relève le niveau, trace l\'appoint, puis already_synced au rejeu', function () {
    Sanctum::actingAs($this->user);
    $op = refillOp($this->citerne->id, 5000);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res['status'])->toBe('success');

    $this->citerne->refresh();
    expect((float) $this->citerne->current_level_liters)->toBe(7000.0)
        ->and((float) $this->citerne->current_level_percent)->toBe(70.0);

    $reading = WaterReading::where('uuid', $op['payload']['uuid'])->first();
    expect($reading)->not->toBeNull()
        ->and((float) $reading->volume_added_liters)->toBe(5000.0)
        ->and((float) $reading->volume_consumed_liters)->toBe(0.0);

    // Rejeu → already_synced, pas de double-comptage.
    $res2 = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');
    expect($res2['status'])->toBe('already_synced');
    expect((float) $this->citerne->fresh()->current_level_liters)->toBe(7000.0);
});

test('water_refill.create passe même si un relevé du jour existe déjà (pas de collision unique)', function () {
    // Régression « Erreur interne lors de la réconciliation » : un relevé du jour
    // existant ne doit PAS bloquer le push d'un ravitaillement le même jour.
    Sanctum::actingAs($this->user);

    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $this->citerne->id, 'user_id' => $this->user->id,
        'reading_date' => now()->toDateString(), 'volume_consumed_liters' => 300, 'volume_added_liters' => 0,
        'is_refill' => false,
    ]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [refillOp($this->citerne->id, 5000)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');
    expect((float) $this->citerne->fresh()->current_level_liters)->toBe(7000.0);
    expect(WaterReading::where('water_source_id', $this->citerne->id)->where('is_refill', true)->count())->toBe(1);
});

test('water_refill.create est refusé sans ressources.C', function () {
    $viewer = User::factory()->create(['role_id' => refillRole('lecteur_eau', ['L'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $viewer->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($viewer);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [refillOp($this->citerne->id, 3000)]])
        ->assertOk()->json('results.0');

    expect($res['status'])->toBe('permission_denied');
    expect((float) $this->citerne->fresh()->current_level_liters)->toBe(2000.0);
});

test('le pull expose les citernes de la ferme (ressources.L)', function () {
    Sanctum::actingAs($this->user);
    $names = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.water_sources.upserts'))
        ->pluck('name');

    expect($names)->toContain('Citerne A');
});

test('un utilisateur qui peut ravitailler (ressources.C sans L) reçoit AUSSI les citernes', function () {
    // C sans L : le gate any-of du pull doit quand même descendre les citernes,
    // sinon l'écran de ravitaillement afficherait « Aucune citerne ».
    $creator = User::factory()->create(['role_id' => refillRole('createur_eau', ['C'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $creator->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($creator);

    $names = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.water_sources.upserts'))
        ->pluck('name');

    expect($names)->toContain('Citerne A');
});
