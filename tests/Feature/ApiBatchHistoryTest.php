<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Farm;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * GET /api/v1/batches/{batch}/history — fiche lot enrichie : indicateurs
 * (âge, effectif, mortalité, GMQ) + historique des pointages (série de poids).
 */

function bhRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'elevage')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-BH'], ['name' => 'Ferme BH', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => bhRole('technicien_bh', ['L'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);

    $species = Species::firstOrCreate(['slug' => 'poulet-bh'], ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]);
    $type = ProductionType::resolveOrCreate('chair', $species->id);
    $this->batch = Batch::factory()->create([
        'farm_id' => $this->farm->id,
        'building_id' => Building::factory()->create(['farm_id' => $this->farm->id, 'type' => 'chair'])->id,
        'production_type_id' => $type->id, 'status' => 'Actif', 'code' => 'LOT-BH',
        'initial_quantity' => 1000, 'current_quantity' => 1000,
        'avg_weight_start' => 0.040, 'arrival_date' => now()->subDays(20)->toDateString(),
    ]);

    // Deux pointages avec poids croissant (40g → 500g → 1200g).
    DailyCheck::create(['farm_id' => $this->farm->id, 'batch_id' => $this->batch->id, 'check_date' => now()->subDays(10)->toDateString(), 'mortality' => 5, 'avg_weight' => 0.500, 'health_status' => 'Normal']);
    DailyCheck::create(['farm_id' => $this->farm->id, 'batch_id' => $this->batch->id, 'check_date' => now()->toDateString(), 'mortality' => 3, 'avg_weight' => 1.200, 'health_status' => 'Normal']);
});

test('la fiche renvoie indicateurs + historique des pointages', function () {
    Sanctum::actingAs($this->user);
    $json = $this->getJson("/api/v1/batches/{$this->batch->id}/history")->assertOk()->json();

    expect($json['batch']['code'])->toBe('LOT-BH')
        ->and($json['batch']['age'])->toBe(21)                     // jour d'arrivée = J1
        ->and($json['batch']['total_mortality'])->toBe(8)          // 5 + 3
        ->and($json['batch']['latest_weight'])->toEqual(1.2)
        ->and($json['checks'])->toHaveCount(2);

    // GMQ = (1.200 - 0.040) kg * 1000 / 21 j ≈ 55.2 g/j.
    expect((float) $json['batch']['gmq'])->toEqual(55.2);
});

test('la fiche est refusée sans droit elevage.L (403)', function () {
    $noRole = bhRole('sans_elevage_bh', []);
    $orphan = User::factory()->create(['role_id' => $noRole->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $orphan->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($orphan);

    $this->getJson("/api/v1/batches/{$this->batch->id}/history")->assertStatus(403);
});
