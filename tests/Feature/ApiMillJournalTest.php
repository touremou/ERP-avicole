<?php

use App\Models\Farm;
use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * GET /api/v1/provenderie/today — journal de production Provenderie : OP du
 * jour + récap (produits / en cours / planifiés, total kg), gardé
 * provenderie.L et borné à la ferme.
 */

function mjRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'provenderie')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

function mjOp(int $farmId, int $formulaId, string $batch, string $status, float $kg, int $operatorId): MillProduction
{
    return MillProduction::create([
        'farm_id' => $farmId, 'formula_id' => $formulaId, 'batch_number' => $batch,
        'status' => $status, 'quantity_produced' => $kg, 'operator_id' => $operatorId,
    ]);
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-MJ'], ['name' => 'Ferme Mill', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => mjRole('meunier_mj', ['L'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
    $this->formula = Formula::factory()->create(['farm_id' => $this->farm->id, 'name' => 'Ponte 1']);
});

test('le journal renvoie les OP du jour + récap (total kg sur les OP terminées)', function () {
    mjOp($this->farm->id, $this->formula->id, 'OP-1', 'Terminé', 1000, $this->user->id);
    mjOp($this->farm->id, $this->formula->id, 'OP-2', 'Terminé', 500, $this->user->id);
    mjOp($this->farm->id, $this->formula->id, 'OP-3', 'En cours', 0, $this->user->id);
    // OP d'hier : exclue (scopeToday = created_at aujourd'hui).
    $old = mjOp($this->farm->id, $this->formula->id, 'OP-OLD', 'Terminé', 9999, $this->user->id);
    $old->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    Sanctum::actingAs($this->user);
    $json = $this->getJson('/api/v1/provenderie/today')->assertOk()->json();

    expect($json['productions'])->toHaveCount(3);
    expect($json['summary']['total'])->toBe(3)
        ->and($json['summary']['done'])->toBe(2)
        ->and($json['summary']['in_progress'])->toBe(1)
        ->and((float) $json['summary']['total_kg'])->toEqual(1500.0);
});

test('sans droit provenderie.L le journal est refusé (403)', function () {
    $orphan = User::factory()->create(['role_id' => mjRole('sans_prov', [])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $orphan->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($orphan);

    $this->getJson('/api/v1/provenderie/today')->assertStatus(403);
});

test('le journal est borné à la ferme courante', function () {
    $otherFarm = Farm::firstOrCreate(['code' => 'FT-MJ2'], ['name' => 'Autre', 'is_active' => true]);
    $otherFormula = Formula::factory()->create(['farm_id' => $otherFarm->id, 'name' => 'X']);
    mjOp($otherFarm->id, $otherFormula->id, 'OP-X', 'Terminé', 8888, $this->user->id);
    mjOp($this->farm->id, $this->formula->id, 'OP-OK', 'Terminé', 300, $this->user->id);

    Sanctum::actingAs($this->user);
    $batches = collect($this->getJson('/api/v1/provenderie/today')->assertOk()->json('productions'))->pluck('batch_number');

    expect($batches)->toContain('OP-OK')->not->toContain('OP-X');
});
