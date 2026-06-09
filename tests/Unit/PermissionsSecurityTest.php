<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $permL = Permission::firstOrCreate(['name' => 'L'], ['description' => 'Lecture']);
    $permC = Permission::firstOrCreate(['name' => 'C'], ['description' => 'Création']);
    $permM = Permission::firstOrCreate(['name' => 'M'], ['description' => 'Modification']);
    $permS = Permission::firstOrCreate(['name' => 'S'], ['description' => 'Suppression']);

    $admin = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Administrateur', 'icon' => '👑']);
    $admin->permissions()->syncWithoutDetaching([$permL->id, $permC->id, $permM->id, $permS->id]);

    $manager = Role::firstOrCreate(['name' => 'manager'], ['display_name' => 'Manager', 'icon' => '🛠️']);
    $manager->permissions()->syncWithoutDetaching([$permL->id, $permC->id, $permM->id]);

    $operator = Role::firstOrCreate(['name' => 'operateur'], ['display_name' => 'Opérateur', 'icon' => '📋']);
    $operator->permissions()->syncWithoutDetaching([$permL->id, $permC->id]);

    $readonly = Role::firstOrCreate(['name' => 'visiteur'], ['display_name' => 'Visiteur', 'icon' => '👁️']);
    $readonly->permissions()->syncWithoutDetaching([$permL->id]);

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
    $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
    $this->readonlyUser = User::factory()->create(['role_id' => $readonly->id]);

    $this->building = Building::factory()->create();
    $this->employee = Employee::factory()->create();
    $this->provider = Provider::factory()->create();
});

test('B-14 : en mode offline, seules L et C sont accordées', function () {
    config(['app.database_down' => true]);
    $this->actingAs($this->readonlyUser);

    expect(Gate::allows('L'))->toBeTrue();
    expect(Gate::allows('C'))->toBeTrue();
    expect(Gate::allows('M'))->toBeFalse();
    expect(Gate::allows('S'))->toBeFalse();
});

test('B-14 : en mode online, les permissions RBAC normales s\'appliquent', function () {
    config(['app.database_down' => false]);

    $this->actingAs($this->adminUser);
    expect(Gate::allows('L'))->toBeTrue();
    expect(Gate::allows('S'))->toBeTrue();

    $this->actingAs($this->readonlyUser);
    expect(Gate::allows('L'))->toBeTrue();
    expect(Gate::allows('C'))->toBeFalse();
});

test('B-20 : un user sans role_id n\'a aucune permission en mode online', function () {
    config(['app.database_down' => false]);
    $noRoleUser = User::factory()->create(['role_id' => null]);

    $this->actingAs($noRoleUser);
    expect(Gate::allows('L'))->toBeFalse();
    expect(Gate::allows('S'))->toBeFalse();
});

test('S-17 : un manager peut lister les employés', function () {
    $this->actingAs($this->managerUser)
        ->get(route('employees.index'))
        ->assertOk();
});

test('B-21 : reconcile rejette les données invalides', function () {
    $this->actingAs($this->operatorUser)
        ->postJson('/api/sync/reconcile', ['building_id' => 999999])
        ->assertStatus(422);
});

test('B-21 : reconcile accepte des données valides avec permission C', function () {
    $this->actingAs($this->operatorUser)
        ->postJson('/api/sync/reconcile', [
            'uuid'                 => fake()->uuid(),
            'code'                 => 'SYNC-TEST-001',
            'type'                 => 'chair',
            'building_id'          => $this->building->id,
            'initial_quantity'     => 500,
            'current_quantity'     => 500,
            'qty_dead'             => 0,
            'arrival_mortality_rate' => 0,
            'status'               => 'Actif',
            'arrival_date'         => now()->toDateString(),
            'employee_id'          => $this->employee->id,
            'provider_id'          => $this->provider->id,
            'updated_at'           => now()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    expect(Batch::where('code', 'SYNC-TEST-001')->exists())->toBeTrue();
});

test('B-21 : reconcile refuse un visiteur (pas de permission C)', function () {
    $this->actingAs($this->readonlyUser)
        ->postJson('/api/sync/reconcile', [
            'uuid'             => fake()->uuid(),
            'code'             => 'SYNC-FAIL',
            'type'             => 'chair',
            'building_id'      => $this->building->id,
            'initial_quantity' => 100,
            'current_quantity' => 100,
            'arrival_date'     => now()->toDateString(),
            'employee_id'      => $this->employee->id,
            'provider_id'      => $this->provider->id,
            'updated_at'       => now()->toIso8601String(),
        ])
        ->assertStatus(403);
});

test('B-21 : conflit détecté si le serveur a une version plus récente', function () {
    $batch = Batch::factory()->create([
        'uuid'        => $uuid = fake()->uuid(),
        'building_id' => $this->building->id,
        'status'      => 'Actif',
        'updated_at'  => now(),
    ]);

    $this->actingAs($this->managerUser)
        ->postJson('/api/sync/reconcile', [
            'uuid'             => $uuid,
            'code'             => $batch->code,
            'type'             => $batch->type,
            'building_id'      => $this->building->id,
            'initial_quantity' => $batch->initial_quantity,
            'current_quantity' => $batch->current_quantity,
            'arrival_date'     => $batch->arrival_date,
            'employee_id'      => $this->employee->id,
            'provider_id'      => $this->provider->id,
            'updated_at'       => now()->subDays(5)->toIso8601String(),
        ])
        ->assertOk()
        ->assertJson(['status' => 'conflict']);
});

test('la matrice RBAC est cohérente', function () {
    expect($this->adminUser->hasPermission('S'))->toBeTrue();
    expect($this->operatorUser->hasPermission('C'))->toBeTrue();
    expect($this->operatorUser->hasPermission('M'))->toBeFalse();
    expect($this->readonlyUser->hasPermission('C'))->toBeFalse();
});
