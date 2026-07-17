<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * GET /api/v1/treasury/today — journal de trésorerie du jour (mobile) :
 * mouvements du jour + récap (encaissé/décaissé/net) + soldes par compte,
 * gardé tresorerie.L et borné à la ferme.
 */

function tjRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'tresorerie')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

function tjMovement(int $farmId, int $accountId, string $direction, float $amount, string $date): TreasuryTransaction
{
    return TreasuryTransaction::create([
        'farm_id' => $farmId, 'treasury_account_id' => $accountId, 'direction' => $direction,
        'amount' => $amount, 'transaction_date' => $date, 'category' => 'divers',
    ]);
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-TJ'], ['name' => 'Ferme Tréso', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => tjRole('tresorier_tj', ['L'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
    $this->account = TreasuryAccount::create([
        'farm_id' => $this->farm->id, 'name' => 'Caisse', 'type' => 'caisse',
        'opening_balance' => 100000, 'current_balance' => 130000, 'is_active' => true,
    ]);
});

test('le journal renvoie les mouvements du jour + récap + soldes', function () {
    tjMovement($this->farm->id, $this->account->id, 'in', 50000, now()->toDateString());
    tjMovement($this->farm->id, $this->account->id, 'out', 20000, now()->toDateString());
    tjMovement($this->farm->id, $this->account->id, 'in', 99999, now()->subDay()->toDateString()); // hier, exclu

    Sanctum::actingAs($this->user);
    $json = $this->getJson('/api/v1/treasury/today')->assertOk()->json();

    expect($json['movements'])->toHaveCount(2);
    expect((float) $json['summary']['in'])->toEqual(50000.0)
        ->and((float) $json['summary']['out'])->toEqual(20000.0)
        ->and((float) $json['summary']['net'])->toEqual(30000.0);
    // Soldes courants par compte + total.
    expect((float) $json['total_balance'])->toEqual(130000.0)
        ->and($json['accounts'])->toHaveCount(1);
});

test('sans droit tresorerie.L le journal est refusé (403)', function () {
    $orphan = User::factory()->create(['role_id' => tjRole('sans_treso', [])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $orphan->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($orphan);

    $this->getJson('/api/v1/treasury/today')->assertStatus(403);
});

test('le journal est borné à la ferme courante', function () {
    $otherFarm = Farm::firstOrCreate(['code' => 'FT-TJ2'], ['name' => 'Autre', 'is_active' => true]);
    $otherAccount = TreasuryAccount::create([
        'farm_id' => $otherFarm->id, 'name' => 'Caisse X', 'type' => 'caisse',
        'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true,
    ]);
    tjMovement($otherFarm->id, $otherAccount->id, 'in', 88000, now()->toDateString());
    tjMovement($this->farm->id, $this->account->id, 'in', 33000, now()->toDateString());

    Sanctum::actingAs($this->user);
    $amounts = collect($this->getJson('/api/v1/treasury/today')->assertOk()->json('movements'))
        ->pluck('amount')->map(fn ($v) => (float) $v);

    expect($amounts)->toContain(33000.0)->not->toContain(88000.0);
});
