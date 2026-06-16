<?php

/**
 * Tests Feature — Dashboard
 *
 * Couvre : DS-01 (pas de crash), mode offline, accès permissions
 */

use App\Models\Building;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Setup RBAC directement
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    // La matrice `module_permissions` (Modules × Rôles) est la SEULE source
    // de vérité des Gates (cf. AppServiceProvider) : on dérive ici une ligne
    // par module à partir de la matrice LCMS (L/C/M/S) de chaque rôle.
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

    $admin    = $makeRole('admin',  ['L', 'C', 'M', 'S']);
    $readonly = $makeRole('viewer', ['L']);

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
    $this->readonlyUser = User::factory()->create(['role_id' => $readonly->id]);
});

test('le dashboard charge sans crash (DS-01 corrigé)', function () {
    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Undefined variable');
});

test('le dashboard est accessible à un visiteur (L)', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('dashboard'))
        ->assertOk();
});

test('le dashboard affiche les KPI de base', function () {
    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        // KPI de base, toujours rendu (le KPI Ponte/HDP est conditionnel à
        // l'existence d'un lot de ponte).
        ->assertSee('Effectif Actif');
});

test('un utilisateur non connecté est redirigé vers login', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('le taux de mortalité global inclut la mortalité d\'arrivage (qty_dead)', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);

    // 1000 reçus vivants + 20 morts au transport, aucun pointage : l'ancienne
    // formule (initial − current) donnait 0%. La nouvelle compte qty_dead.
    App\Models\Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'initial_quantity' => 1000,
        'current_quantity' => 1000,
        'qty_dead'         => 20,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        // 20 / (1000 + 20) = 1.96 %
        ->assertViewHas('globalMortalityRate', fn ($v) => round($v, 2) === 1.96);
});

test('le HDP n\'est pas dilué par les lots qui ne pondent pas', function () {
    $species = App\Models\Species::firstOrCreate(
        ['slug' => 'poulet'],
        ['name_fr' => 'Poulet', 'family' => 'volaille', 'is_active' => true]
    );

    $ponteType = App\Models\ProductionType::updateOrCreate(
        ['species_id' => $species->id, 'slug' => 'ponte'],
        ['name_fr' => 'Ponte', 'metrics_enabled' => ['eggs' => true], 'is_active' => true]
    );
    $chairType = App\Models\ProductionType::updateOrCreate(
        ['species_id' => $species->id, 'slug' => 'chair'],
        ['name_fr' => 'Chair', 'metrics_enabled' => ['eggs' => false], 'is_active' => true]
    );

    $building = Building::factory()->create(['type' => 'ponte', 'capacity' => 5000]);

    $layer = App\Models\Batch::factory()->create([
        'building_id'        => $building->id,
        'production_type_id' => $ponteType->id,
        'status'             => 'Actif',
        'initial_quantity'   => 1000,
        'current_quantity'   => 950,
    ]);

    // Lot chair présent mais qui ne pond pas : ne doit PAS diluer le HDP.
    App\Models\Batch::factory()->create([
        'building_id'        => $building->id,
        'production_type_id' => $chairType->id,
        'status'             => 'Actif',
        'initial_quantity'   => 500,
        'current_quantity'   => 480,
    ]);

    App\Models\EggProduction::create([
        'batch_id'             => $layer->id,
        'production_date'      => now()->startOfDay(),
        'total_eggs_collected' => 900,
        'broken_eggs'          => 0,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        // 900 / 950 (pondeuses seules) = 94.7 %, et NON 900 / 1430.
        ->assertViewHas('hdp', fn ($v) => round($v, 1) === 94.7);
});

test('un stock sous son seuil déclenche une alerte de réapprovisionnement', function () {
    App\Models\Stock::create([
        'item_name'        => 'Vaccin Newcastle',
        'category'         => App\Models\Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 5,
        'alert_threshold'  => 50,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('lowStocks', fn ($s) => $s->isNotEmpty())
        ->assertSee('Vaccin Newcastle');
});

test('un taux de picage élevé déclenche une alerte bien-être', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);

    $batch = App\Models\Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'initial_quantity' => 1000,
        'current_quantity' => 1000,
    ]);

    // 50 sujets victimes de picage sur 1000 = 5 % > seuil par défaut (2 %).
    App\Models\DailyCheck::factory()->create([
        'batch_id'             => $batch->id,
        'check_date'           => now()->subDay(),
        'pecking_injury_count' => 50,
        'lame_count'           => 0,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('welfareAlerts', fn ($a) => $a->isNotEmpty()
            && $a->first()['issues'][0]['type'] === 'Picage');
});

test('la marge nette est masquée pour un utilisateur sans droit commerce', function () {
    $this->seed(Database\Seeders\ModuleSeeder::class);

    // Rôle élevage : lecture sur tous les modules SAUF commerce.
    $role = Role::firstOrCreate(
        ['name' => 'eleveur'],
        ['label' => 'Éleveur', 'display_name' => 'Éleveur', 'permissions' => ['L']]
    );
    $now = now();
    foreach (App\Models\Module::all() as $module) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $module->id],
            [
                'can_read'   => $module->slug !== 'commerce', // commerce refusé
                'can_create' => false, 'can_modify' => false, 'can_delete' => false,
                'updated_at' => $now, 'created_at' => $now,
            ]
        );
    }
    $eleveur = User::factory()->create(['role_id' => $role->id]);

    // L'admin (bypass) voit bien la marge → le bloc existe.
    $this->actingAs($this->adminUser)->get(route('dashboard'))->assertSee('Marge Nette Mensuelle');

    // L'éleveur sans commerce.L ne doit PAS voir la marge ni l'encours clients.
    $this->actingAs($eleveur)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Marge Nette Mensuelle')
        ->assertDontSee('Encours Clients');
});
