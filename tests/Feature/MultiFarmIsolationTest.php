<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Client;
use App\Models\Farm;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Étanchéité multi-fermes (isolation tenant). Vérifie que les données d'une
 * ferme ne fuient JAMAIS vers l'utilisateur d'une autre — sur les quatre
 * frontières : liaison de route (web), listes scopées (web), pull API,
 * en-tête X-Farm-Id (API), commutation de ferme, écriture croisée (sync),
 * et le repli « fail-closed » d'un utilisateur sans affectation.
 */

function allModulesRole(string $name): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => ['L', 'C', 'M']]);
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false,
             'created_at' => $now, 'updated_at' => $now]
        );
    }

    return $role;
}

function farmBatch(int $farmId, string $code): Batch
{
    $species = Species::firstOrCreate(['slug' => 'poulet-iso'], ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]);
    $type = ProductionType::resolveOrCreate('chair', $species->id);

    return Batch::factory()->create([
        'farm_id'            => $farmId,
        'building_id'        => Building::factory()->create(['farm_id' => $farmId, 'type' => 'chair'])->id,
        'production_type_id' => $type->id,
        'status'             => 'Actif',
        'code'               => $code,
    ]);
}

beforeEach(function () {
    // farmA créée en premier → plus petit id → ferme par défaut (Farm::defaultId).
    $this->farmA = Farm::create(['code' => 'ISO-A', 'name' => 'Ferme A', 'is_active' => true]);
    $this->farmB = Farm::create(['code' => 'ISO-B', 'name' => 'Ferme B', 'is_active' => true]);

    $role = allModulesRole('iso_role');

    $this->userA = User::factory()->create(['role_id' => $role->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farmA->id, 'user_id' => $this->userA->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Utilisateur SANS aucune affectation ferme (cas fail-open historique).
    $this->userNoFarm = User::factory()->create(['role_id' => $role->id]);

    // Données réelles dans chaque ferme.
    $this->batchA = farmBatch($this->farmA->id, 'LOT-A-ISO');
    $this->batchB = farmBatch($this->farmB->id, 'LOT-B-ISO');
});

test('liaison de route web : un lot d\'une autre ferme renvoie 404', function () {
    $this->actingAs($this->userA)->withSession(['current_farm_id' => $this->farmA->id])
        ->get(route('batches.show', $this->batchA))->assertOk();

    $this->actingAs($this->userA)->withSession(['current_farm_id' => $this->farmA->id])
        ->get(route('batches.show', $this->batchB))->assertNotFound();
});

test('liste web : la liste des lots ne montre que la ferme courante', function () {
    $this->actingAs($this->userA)->withSession(['current_farm_id' => $this->farmA->id])
        ->get(route('batches.index'))->assertOk()
        ->assertSee('LOT-A-ISO')
        ->assertDontSee('LOT-B-ISO');
});

test('pull API : ne descend que les lots de la ferme de l\'utilisateur', function () {
    Sanctum::actingAs($this->userA);
    $codes = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.batches.upserts'))
        ->pluck('code');

    expect($codes)->toContain('LOT-A-ISO');
    expect($codes)->not->toContain('LOT-B-ISO');
});

test('API : X-Farm-Id vers une ferme non affectée est refusé (403)', function () {
    Sanctum::actingAs($this->userA);
    $this->withHeader('X-Farm-Id', (string) $this->farmB->id)
        ->getJson('/api/v1/batches')
        ->assertStatus(403);
});

test('commutation web : ?farm_id vers une ferme non affectée est ignorée', function () {
    $this->actingAs($this->userA)->withSession(['current_farm_id' => $this->farmA->id])
        ->get(route('batches.index', ['farm_id' => $this->farmB->id]))
        ->assertOk()
        ->assertDontSee('LOT-B-ISO'); // le switch a été refusé → reste sur farm A
});

test('sync : pousser un pointage sur le lot d\'une autre ferme n\'affecte PAS cette ferme', function () {
    Sanctum::actingAs($this->userA); // contexte ferme A (défaut)

    $response = $this->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type'    => 'daily_check.create',
            'payload' => [
                'uuid'       => (string) Str::uuid(),
                'batch_id'   => $this->batchB->id, // lot de la ferme B !
                'check_date' => now()->toDateString(),
                'mortality'  => 10,
            ],
        ]],
    ])->assertOk();

    // Rejet DÈS LA VALIDATION (FK bornée à la ferme courante), pas seulement
    // en aval : première ligne explicite. L'op n'est jamais appliquée.
    expect($response->json('results.0.status'))->toBe('validation_failed');
    expect(\App\Models\DailyCheck::withoutGlobalScopes()->where('batch_id', $this->batchB->id)->count())->toBe(0);
});

test('fail-closed : un utilisateur sans affectation ne voit pas TOUTES les fermes', function () {
    // Pas de withSession : c'est SetCurrentFarm qui doit résoudre une ferme
    // par défaut (repli Farm::defaultId) au lieu de laisser le scope inactif.
    $this->actingAs($this->userNoFarm)
        ->get(route('batches.index'))
        ->assertOk()
        ->assertDontSee('LOT-B-ISO'); // jamais « toutes les fermes »
});
