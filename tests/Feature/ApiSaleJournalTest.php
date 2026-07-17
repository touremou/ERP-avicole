<?php

use App\Models\Client;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * GET /api/v1/sales/today — journal des ventes du jour (mobile). Renvoie les
 * ventes du jour de la ferme, un récap (CA sur ventes engagées), et respecte
 * le droit commerce.L + l'étanchéité multi-fermes.
 */

function jrnRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'commerce')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => false, 'can_delete' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

function jrnSale(int $farmId, int $clientId, int $userId, string $status, float $total, float $paid, string $date): Sale
{
    return Sale::create([
        'farm_id' => $farmId, 'uuid' => (string) Str::uuid(), 'reference' => 'BL-' . Str::random(6),
        'client_id' => $clientId, 'user_id' => $userId, 'sale_date' => $date, 'type' => 'bon_livraison',
        'status' => $status, 'subtotal' => $total, 'total_amount' => $total, 'paid_amount' => $paid,
        'payment_status' => $paid >= $total ? 'solde' : ($paid > 0 ? 'partiel' : 'impaye'),
    ]);
}

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-JRN'], ['name' => 'Ferme Journal', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => jrnRole('vendeur_jrn', ['L', 'C'])->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    session(['current_farm_id' => $this->farm->id]);
    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-JRN', 'name' => 'Client Journal',
        'type' => 'particulier', 'category' => 'detaillant', 'phone' => '620000123', 'credit_limit' => 0, 'status' => 'actif',
    ]);
});

test('le journal renvoie les ventes du jour + un récap sur les ventes engagées', function () {
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'valide', 100000, 100000, now()->toDateString());
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'livre', 50000, 20000, now()->toDateString());
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'brouillon', 999999, 0, now()->toDateString()); // hors CA
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'valide', 77000, 77000, now()->subDay()->toDateString()); // hier

    Sanctum::actingAs($this->user);
    $json = $this->getJson('/api/v1/sales/today')->assertOk()->json();

    // 3 ventes du jour listées (brouillon inclus dans la liste), pas celle d'hier.
    expect($json['sales'])->toHaveCount(3);
    // Récap : CA = ventes engagées seulement (100k + 50k = 150k), brouillon exclu.
    expect($json['summary']['count'])->toBe(2)
        ->and((float) $json['summary']['total'])->toEqual(150000.0)
        ->and((float) $json['summary']['paid'])->toEqual(120000.0)
        ->and((float) $json['summary']['remaining'])->toEqual(30000.0);
});

test('sans droit commerce.L le journal est refusé (403)', function () {
    $noRole = jrnRole('sans_commerce', []); // aucune permission commerce
    $user = User::factory()->create(['role_id' => $noRole->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/sales/today')->assertStatus(403);
});

test('le journal est borné à la ferme courante', function () {
    $otherFarm = Farm::firstOrCreate(['code' => 'FT-JRN2'], ['name' => 'Autre', 'is_active' => true]);
    $otherClient = Client::create([
        'farm_id' => $otherFarm->id, 'client_id' => 'CLI-X', 'name' => 'X',
        'type' => 'particulier', 'category' => 'detaillant', 'phone' => '620999000', 'credit_limit' => 0, 'status' => 'actif',
    ]);
    jrnSale($otherFarm->id, $otherClient->id, $this->user->id, 'valide', 88000, 88000, now()->toDateString());
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'valide', 33000, 33000, now()->toDateString());

    Sanctum::actingAs($this->user); // ferme courante = $this->farm
    $refs = collect($this->getJson('/api/v1/sales/today')->assertOk()->json('sales'))
        ->pluck('total_amount')->map(fn ($v) => (float) $v);

    expect($refs)->toContain(33000.0)->not->toContain(88000.0);
});

test('le paramètre period sélectionne la fenêtre (today / yesterday / 7days)', function () {
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'valide', 10000, 10000, now()->toDateString());
    jrnSale($this->farm->id, $this->client->id, $this->user->id, 'valide', 20000, 20000, now()->subDay()->toDateString());

    Sanctum::actingAs($this->user);

    $today = $this->getJson('/api/v1/sales/today')->assertOk()->json();
    expect($today['sales'])->toHaveCount(1)->and($today['period']['key'])->toBe('today');

    $yesterday = $this->getJson('/api/v1/sales/today?period=yesterday')->assertOk()->json();
    expect($yesterday['sales'])->toHaveCount(1)->and($yesterday['period']['key'])->toBe('yesterday');

    $week = $this->getJson('/api/v1/sales/today?period=7days')->assertOk()->json();
    expect($week['sales'])->toHaveCount(2)->and($week['period']['key'])->toBe('7days');
});
