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
use App\Services\LicenseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * Verrou commercial (licence) côté API mobile : un module NON inclus dans
 * l'abonnement est refusé même si l'utilisateur a le droit RBAC. Le paywall
 * ne doit pas pouvoir être contourné via la PWA (endpoints + /sync/pull).
 * RBAC (droit) et licence (abonnement) sont deux verrous cumulés.
 */

/** Forge un code de licence signé (modules au choix), plan actif 1 an. */
function apiLicenseCode(string $privateKey, array $modules): string
{
    $now = now();

    return LicenseService::sign([
        'v' => 1, 'id' => 'BIOCREST', 'client' => 'BioCrest', 'plan' => 'custom',
        'modules' => $modules, 'max_users' => 0, 'max_farms' => 0, 'sms_quota' => 1000,
        'iat' => $now->getTimestamp(), 'nbf' => $now->getTimestamp(),
        'exp' => $now->copy()->addDays(366)->getTimestamp(),
    ], $privateKey);
}

beforeEach(function () {
    Cache::flush();
    $keys = LicenseService::generateKeypair();
    config()->set('license.public_key', $keys['public']);
    config()->set('license.enforce', true);

    // Rôle avec TOUS les droits RBAC (L/C/M) sur tous les modules : le seul
    // frein sera donc la licence, pas le RBAC.
    $role = Role::firstOrCreate(['name' => 'lic_all'], ['label' => 'All', 'display_name' => 'All', 'permissions' => ['L', 'C', 'M']]);
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false,
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    $this->farm = Farm::create(['code' => 'LIC-API', 'name' => 'Ferme Licence', 'is_active' => true]);
    $this->user = User::factory()->create(['role_id' => $role->id]);
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->user->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    session(['current_farm_id' => $this->farm->id]);
    $species = Species::firstOrCreate(['slug' => 'poulet-lic'], ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]);
    $type = ProductionType::resolveOrCreate('chair', $species->id);
    Batch::factory()->create([
        'farm_id' => $this->farm->id,
        'building_id' => Building::factory()->create(['farm_id' => $this->farm->id, 'type' => 'chair'])->id,
        'production_type_id' => $type->id, 'status' => 'Actif', 'code' => 'LOT-LIC',
    ]);
    Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-LIC', 'name' => 'Client Licence',
        'type' => 'particulier', 'category' => 'detaillant', 'phone' => '620000009',
        'credit_limit' => 0, 'status' => 'actif',
    ]);

    // Abonnement : commerce SEULEMENT (pas d'élevage), bien que le RBAC autorise tout.
    $this->keys = $keys;
    app(LicenseService::class)->activate('BIOCREST', apiLicenseCode($keys['private'], ['commerce']));
});

test('API : un endpoint de module hors licence est refusé même avec le droit RBAC', function () {
    Sanctum::actingAs($this->user);

    // elevage.L accordé par la matrice, mais élevage HORS abonnement → 403.
    $this->getJson('/api/v1/batches')->assertStatus(403);
});

test('pull API : le paywall exclut les référentiels des modules hors licence', function () {
    Sanctum::actingAs($this->user);

    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    // Commerce inclus dans l'abonnement → clients descendus.
    expect($entities['clients']['upserts'])->not->toBeEmpty();

    // Élevage hors abonnement → aucun lot, malgré le droit RBAC elevage.L.
    expect($entities['batches']['upserts'])->toBeEmpty();
});

test('avec un abonnement incluant élevage, les lots redescendent (contrôle)', function () {
    // On remplace la licence par une incluant élevage.
    \App\Models\License::query()->delete();
    Cache::flush();
    app(LicenseService::class)->activate('BIOCREST', apiLicenseCode($this->keys['private'], ['elevage', 'commerce']));

    Sanctum::actingAs($this->user);
    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    expect($entities['batches']['upserts'])->not->toBeEmpty();
    $this->getJson('/api/v1/batches')->assertOk();
});
