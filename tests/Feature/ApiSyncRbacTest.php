<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Client;
use App\Models\Farm;
use App\Models\Formula;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Species;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * RBAC de l'API mobile — /sync/pull ne doit descendre au terrain QUE les
 * référentiels des modules lisibles par l'utilisateur (cloisonnement),
 * et /sync/push refuse une opération d'un module non autorisé. Équivalent
 * mobile de la fuite du tableau de bord (exemple 2 de l'audit).
 */

function apiRbacRole(string $name, array $moduleLevels): Role
{
    // $moduleLevels = ['commerce' => ['L','C'], 'elevage' => ['L'], ...]
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
    $this->farm = Farm::firstOrCreate(['code' => 'FA-RBAC'], ['name' => 'Ferme RBAC', 'is_active' => true]);

    // Vendeur : commerce L+C uniquement.
    $this->vendeur = User::factory()->create([
        'role_id' => apiRbacRole('api_vendeur', ['commerce' => ['L', 'C']])->id,
    ]);
    // Éleveur : elevage L+C uniquement.
    $this->eleveur = User::factory()->create([
        'role_id' => apiRbacRole('api_eleveur', ['elevage' => ['L', 'C']])->id,
    ]);

    foreach ([$this->vendeur, $this->eleveur] as $user) {
        DB::table('farm_user')->insert([
            'farm_id' => $this->farm->id, 'user_id' => $user->id,
            'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    session(['current_farm_id' => $this->farm->id]);

    // Donnée élevage (lot) + donnée commerce (client) bien réelles.
    $species = Species::firstOrCreate(['slug' => 'poulet-api-rbac'], ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]);
    $type = ProductionType::resolveOrCreate('chair', $species->id);
    Batch::factory()->create([
        'farm_id' => $this->farm->id,
        'building_id' => Building::factory()->create(['type' => 'chair'])->id,
        'production_type_id' => $type->id,
        'status' => 'Actif', 'code' => 'LOT-API-RBAC',
    ]);
    Stock::factory()->create(['farm_id' => $this->farm->id, 'item_name' => 'Aliment API RBAC']);
    Formula::factory()->create(['farm_id' => $this->farm->id]);
    Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-API-RBAC', 'name' => 'Client API RBAC',
        'type' => 'particulier', 'category' => 'detaillant', 'phone' => '620000001',
        'credit_limit' => 0, 'status' => 'actif',
    ]);
});

test("le vendeur (commerce.L) ne reçoit PAS les lots/stocks/formules via /sync/pull", function () {
    Sanctum::actingAs($this->vendeur);

    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    // Commerce : autorisé → clients présents.
    expect($entities['clients']['upserts'])->not->toBeEmpty();

    // Autres modules : cloisonnés → vides.
    expect($entities['batches']['upserts'])->toBeEmpty();
    expect($entities['stocks']['upserts'])->toBeEmpty();
    expect($entities['formulas']['upserts'])->toBeEmpty();
});

test("l'éleveur (elevage.L) reçoit les lots mais PAS les clients (commerce)", function () {
    Sanctum::actingAs($this->eleveur);

    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    expect($entities['batches']['upserts'])->not->toBeEmpty();
    expect($entities['clients']['upserts'])->toBeEmpty();
    expect($entities['formulas']['upserts'])->toBeEmpty();
});

test("le vendeur ne peut PAS pousser une opération d'élevage (/sync/push refuse)", function () {
    Sanctum::actingAs($this->vendeur);

    $batch = Batch::first();
    $response = $this->postJson('/api/v1/sync/push', [
        'operations' => [[
            'op_uuid' => (string) Str::uuid(),
            'type'    => 'daily_check.create',
            'payload' => [
                'uuid' => (string) Str::uuid(),
                'batch_id' => $batch->id,
                'check_date' => now()->toDateString(),
                'mortality' => 5,
            ],
        ]],
    ])->assertOk();

    // L'op est refusée (permission_denied), pas exécutée.
    expect($response->json('results.0.status'))->toBe('permission_denied');
    expect(\App\Models\DailyCheck::count())->toBe(0);
});
