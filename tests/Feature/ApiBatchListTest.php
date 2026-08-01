<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * LISTE DES LOTS (mobile) — trouvé en rejouant la suite sur MySQL.
 *
 * `batches.type` a été SUPPRIMÉE en juin (migration 2026_06_13_000005) : le type
 * de production est désormais porté par `production_type_id`, et `type` n'est
 * plus qu'un attribut calculé sur le modèle. Cet écran continuait pourtant à
 * demander la colonne dans son SELECT.
 *
 * Personne ne l'a vu parce que AUCUN test ne passait par cette route — le mobile
 * lit les lots par la synchro, et cet endpoint-ci n'était couvert nulle part.
 * En production la requête échoue en base : 500 au terrain.
 *
 * Ce test ferme le trou : il exerce la route, et vérifie que le type de
 * production reste exposé sous le nom attendu par l'application mobile.
 */

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'AB-001'], ['name' => 'Ferme lots', 'is_active' => true]);

    $role = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M']]
    );

    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false,
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    $this->user = User::factory()->create(['role_id' => $role->id]);

    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    session(['current_farm_id' => $this->farm->id]);

    $building = Building::factory()->create(['type' => 'chair']);

    $this->batch = Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);
});

test('la liste des lots répond, au lieu de tomber en base', function () {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/batches', ['X-Farm-Id' => $this->farm->id])
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->batch->id);
});

test('le type de production reste exposé sous le nom « type »', function () {
    // Ce n'est plus une colonne mais un attribut calculé : le mobile lit ce nom,
    // et le renommer côté serveur casserait silencieusement le terrain.
    Sanctum::actingAs($this->user);

    $payload = $this->getJson('/api/v1/batches', ['X-Farm-Id' => $this->farm->id])
        ->assertOk()
        ->json('data.0');

    expect($payload)->toHaveKey('type')
        ->and($payload['type'])->toBe($this->batch->fresh()->type);
});

test('un lot clôturé ne remonte pas dans la liste par défaut', function () {
    Sanctum::actingAs($this->user);

    $this->batch->update(['status' => 'Terminé']);

    $this->getJson('/api/v1/batches', ['X-Farm-Id' => $this->farm->id])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
