<?php

use App\Models\CropCycle;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Module;
use App\Models\Plot;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * GET /api/v1/cultures/today — journal des récoltes du jour : récoltes + récap
 * (nombre, poids net cumulé), gardé cultures.L et borné à la ferme.
 */

function hjRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'cultures')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

function hjCycle(int $farmId, string $crop): CropCycle
{
    $plot = Plot::create(['uuid' => (string) Str::uuid(), 'farm_id' => $farmId, 'code' => 'P-' . Str::random(4), 'name' => 'Parcelle', 'area_ha' => 1, 'status' => 'active']);

    return CropCycle::create([
        'uuid' => (string) Str::uuid(), 'farm_id' => $farmId, 'plot_id' => $plot->id,
        'code' => 'CY-' . Str::random(4), 'crop_name' => $crop, 'variety' => 'Locale',
        'status' => 'en_cours', 'planting_date' => now()->subMonths(2)->toDateString(),
    ]);
}

function hjHarvest(int $farmId, int $cycleId, float $qty, string $unit, string $date): Harvest
{
    return Harvest::create([
        'farm_id' => $farmId, 'crop_cycle_id' => $cycleId, 'harvest_date' => $date,
        'quantity' => $qty, 'unit' => $unit, 'quality' => 'bonne',
    ]);
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-HJ'], ['name' => 'Ferme Cultures', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => hjRole('agronome_hj', ['L'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
    $this->cycle = hjCycle($this->farm->id, 'Tomate');
});

test('le journal renvoie les récoltes du jour + récap (poids net cumulé)', function () {
    hjHarvest($this->farm->id, $this->cycle->id, 100, 'kg', now()->toDateString());
    hjHarvest($this->farm->id, $this->cycle->id, 50, 'kg', now()->toDateString());
    hjHarvest($this->farm->id, $this->cycle->id, 999, 'kg', now()->subDay()->toDateString()); // hier, exclu

    Sanctum::actingAs($this->user);
    $json = $this->getJson('/api/v1/cultures/today')->assertOk()->json();

    expect($json['harvests'])->toHaveCount(2);
    expect($json['summary']['count'])->toBe(2)
        ->and((float) $json['summary']['total_weight_kg'])->toEqual(150.0);
    expect($json['harvests'][0]['crop'])->toBe('Tomate');
});

test('sans droit cultures.L le journal est refusé (403)', function () {
    $orphan = User::factory()->create(['role_id' => hjRole('sans_cultures', [])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $orphan->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($orphan);

    $this->getJson('/api/v1/cultures/today')->assertStatus(403);
});

test('le journal est borné à la ferme courante', function () {
    $otherFarm = Farm::firstOrCreate(['code' => 'FT-HJ2'], ['name' => 'Autre', 'is_active' => true]);
    $otherCycle = hjCycle($otherFarm->id, 'Maïs');
    hjHarvest($otherFarm->id, $otherCycle->id, 500, 'kg', now()->toDateString());
    hjHarvest($this->farm->id, $this->cycle->id, 30, 'kg', now()->toDateString());

    Sanctum::actingAs($this->user);
    $crops = collect($this->getJson('/api/v1/cultures/today')->assertOk()->json('harvests'))->pluck('crop');

    expect($crops)->toContain('Tomate')->not->toContain('Maïs');
});
