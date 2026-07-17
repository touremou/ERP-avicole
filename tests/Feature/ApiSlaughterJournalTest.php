<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\SlaughterOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * GET /api/v1/abattoir/today — journal d'abattage : ordres prévus/exécutés
 * aujourd'hui + récap (abattus, prévus, bloqués, sujets, poids vif), gardé
 * abattoir.L et borné à la ferme.
 */

function sjRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'abattoir')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-SJ'], ['name' => 'Ferme Abattoir', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => sjRole('operateur_sj', ['L'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
});

function sjOrder(int $farmId, int $userId, string $number, string $status, int $planned, ?int $actual, ?float $weight, string $plannedDate, ?string $actualDate = null): SlaughterOrder
{
    return SlaughterOrder::create([
        'farm_id' => $farmId, 'order_number' => $number, 'status' => $status,
        'planned_quantity' => $planned, 'actual_quantity' => $actual, 'total_live_weight_kg' => $weight,
        'planned_date' => $plannedDate, 'actual_date' => $actualDate, 'requested_by' => $userId,
    ]);
}

test('le journal renvoie les ordres du jour + récap (sujets/poids sur les terminés)', function () {
    sjOrder($this->farm->id, $this->user->id, 'ABT-1', 'planifie', 100, null, null, now()->toDateString());
    sjOrder($this->farm->id, $this->user->id, 'ABT-2', 'termine', 100, 98, 200.0, now()->toDateString(), now()->toDateString());
    sjOrder($this->farm->id, $this->user->id, 'ABT-3', 'bloque', 50, null, null, now()->toDateString());
    // Ordre d'hier (prévu ET exécuté hier) : exclu.
    sjOrder($this->farm->id, $this->user->id, 'ABT-OLD', 'termine', 200, 200, 400.0, now()->subDay()->toDateString(), now()->subDay()->toDateString());

    Sanctum::actingAs($this->user);
    $json = $this->getJson('/api/v1/abattoir/today')->assertOk()->json();

    expect($json['orders'])->toHaveCount(3);
    expect($json['summary']['total'])->toBe(3)
        ->and($json['summary']['done'])->toBe(1)
        ->and($json['summary']['planned'])->toBe(1)
        ->and($json['summary']['blocked'])->toBe(1)
        ->and($json['summary']['slaughtered'])->toBe(98)
        ->and((float) $json['summary']['live_weight_kg'])->toEqual(200.0);
});

test('sans droit abattoir.L le journal est refusé (403)', function () {
    $orphan = User::factory()->create(['role_id' => sjRole('sans_abattoir', [])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $orphan->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($orphan);

    $this->getJson('/api/v1/abattoir/today')->assertStatus(403);
});

test('le journal est borné à la ferme courante', function () {
    $otherFarm = Farm::firstOrCreate(['code' => 'FT-SJ2'], ['name' => 'Autre', 'is_active' => true]);
    sjOrder($otherFarm->id, $this->user->id, 'ABT-X', 'termine', 10, 10, 20.0, now()->toDateString(), now()->toDateString());
    sjOrder($this->farm->id, $this->user->id, 'ABT-OK', 'planifie', 10, null, null, now()->toDateString());

    Sanctum::actingAs($this->user);
    $numbers = collect($this->getJson('/api/v1/abattoir/today')->assertOk()->json('orders'))->pluck('order_number');

    expect($numbers)->toContain('ABT-OK')->not->toContain('ABT-X');
});
