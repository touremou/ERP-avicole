<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * /sync/pull doit descendre les stocks de la ferme courante à tout utilisateur
 * qui a logistique.L — reproduction du symptôme « 0 article » côté PWA.
 */

function stockRole(string $name, array $moduleLevels): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    foreach ($moduleLevels as $slug => $perms) {
        $mod = Module::where('slug', $slug)->value('id');
        if (! $mod) continue;
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $mod],
            ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
             'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    return $role;
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FA-STK'], ['name' => 'Ferme Stock', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => stockRole('api_logisticien', ['logistique' => ['L', 'C']])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
});

test('un logisticien (logistique.L) reçoit les stocks de sa ferme via /sync/pull', function () {
    Stock::factory()->create(['farm_id' => $this->farm->id, 'item_name' => 'Maïs concassé']);

    Sanctum::actingAs($this->user);
    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    expect($entities['stocks']['upserts'])->not->toBeEmpty();
    expect(collect($entities['stocks']['upserts'])->pluck('item_name'))->toContain('Maïs concassé');
});

test('les stocks d\'une AUTRE ferme ne remontent pas (étanchéité)', function () {
    $other = Farm::firstOrCreate(['code' => 'FA-STK2'], ['name' => 'Autre', 'is_active' => true]);
    Stock::factory()->create(['farm_id' => $other->id, 'item_name' => 'Stock Autre Ferme']);
    Stock::factory()->create(['farm_id' => $this->farm->id, 'item_name' => 'Stock Ma Ferme']);

    Sanctum::actingAs($this->user);
    $names = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.stocks.upserts'))->pluck('item_name');

    expect($names)->toContain('Stock Ma Ferme')->not->toContain('Stock Autre Ferme');
});
