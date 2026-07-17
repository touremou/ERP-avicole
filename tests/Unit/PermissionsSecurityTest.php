<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Contexte ferme (trait BelongsToFarm) pour la cohérence farm_id.
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    // La matrice `module_permissions` (Modules × Rôles) est la SEULE source
    // de vérité des Gates (cf. AppServiceProvider) : chaque rôle reçoit ici
    // une ligne par module, dérivée de `roles.permissions` (LCMS), pour
    // reproduire l'état "matrice complète" garanti en production.
    $makeRole = function (string $name, array $perms) {
        $role = Role::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
        );

        $now = now();
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $role;
    };

    $admin    = $makeRole('admin',    ['L', 'C', 'M', 'S']);
    $manager  = $makeRole('manager',  ['L', 'C', 'M']);
    $operator = $makeRole('operator', ['L', 'C']);
    $readonly = $makeRole('viewer',   ['L']);

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

test('les Gates module utilisent des slugs réels (régression rh/couvoir/stocks)', function () {
    // Régression : StockController/IncubationController utilisaient des slugs
    // inexistants (stocks/couvoir) → Gates non définis refusant l'accès à tous
    // les non-admins. Les slugs réels sont logistique (stock), annuaire (tiers),
    // rh (personnel/paie) et production (couvoir/incubation).
    // NB : « rh » est désormais un module RÉEL (cloisonnement Annuaire/RH), il
    // ne fait donc plus partie des slugs interdits plus bas.
    foreach (['logistique', 'annuaire', 'production'] as $slug) {
        expect(Gate::forUser($this->managerUser)->allows("{$slug}.L"))
            ->toBeTrue("Le manager doit pouvoir lire le module {$slug}.");
    }

    // Les anciens slugs erronés ne doivent plus être utilisés dans le code.
    $dirs = [app_path(), resource_path('views')];
    $offenders = [];
    foreach ($dirs as $dir) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if ($file->isDir() || ! in_array($file->getExtension(), ['php'])) continue;
            $code = file_get_contents($file->getPathname());
            if (preg_match("/'(couvoir|stocks)\\.[LCMS]'/", $code)) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
    }
    expect($offenders)->toBe([], 'Slugs RBAC invalides encore présents : ' . implode(', ', $offenders));
});
