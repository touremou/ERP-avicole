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

test('la bannière du Centre de Contrôle ne retient que les alertes critiques', function () {
    // Stock sous seuil mais > 0 → niveau « attention » : reste dans le panneau
    // détaillé, hors bannière critique.
    App\Models\Stock::create([
        'item_name'        => 'Litière Copeaux',
        'category'         => App\Models\Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 5,
        'alert_threshold'  => 50,
    ]);

    // Stock épuisé (0) → niveau « critique » : doit apparaître dans la bannière.
    App\Models\Stock::create([
        'item_name'        => 'Vaccin Gumboro',
        'category'         => App\Models\Stock::CAT_CONSO,
        'unit'             => 'KG',
        'current_quantity' => 0,
        'alert_threshold'  => 20,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('priorityAlerts', function ($alerts) {
            // Toutes les entrées de la bannière sont de niveau critique…
            $allCritical = $alerts->every(fn ($a) => $a['level'] === 'critique');
            // …et le stock épuisé y figure bien.
            $hasEmptyStock = $alerts->contains(fn ($a) => str_contains($a['title'], 'Stocks sous seuil'));
            return $allCritical && $hasEmptyStock;
        });
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

test('un décès isolé sur un petit lot ne déclenche pas de pic de mortalité', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);

    // 1 mort sur 195 = 0,51 % > seuil 0,5 % MAIS sous le plancher absolu (3) :
    // bruit statistique de petit lot, ne doit PAS lever d'alerte critique.
    $batch = App\Models\Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'initial_quantity' => 195,
        'current_quantity' => 194,
    ]);

    App\Models\DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => now(),
        'mortality'  => 1,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('priorityAlerts', fn ($alerts) =>
            $alerts->doesntContain(fn ($a) => $a['title'] === 'Pic de mortalité'));
});

test('un pic de mortalité réel (au-dessus du plancher et du seuil) lève une alerte critique', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);

    // 5 morts sur 195 = 2,56 % : au-dessus du plancher (3) ET du seuil (0,5 %).
    $batch = App\Models\Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'initial_quantity' => 195,
        'current_quantity' => 190,
    ]);

    App\Models\DailyCheck::factory()->create([
        'batch_id'   => $batch->id,
        'check_date' => now(),
        'mortality'  => 5,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('priorityAlerts', fn ($alerts) =>
            $alerts->contains(fn ($a) => $a['title'] === 'Pic de mortalité'));
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

test('un aliment consommé sans article de stock déclenche une alerte silo MANQUANT', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 2000]);

    $batch = App\Models\Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'initial_quantity' => 500,
        'current_quantity' => 500,
    ]);

    // Pointage avec un aliment dont il n'existe aucun article de stock.
    App\Models\DailyCheck::factory()->create([
        'batch_id'       => $batch->id,
        'check_date'     => now()->subDay(),
        'feed_type'      => 'Aliment Bergerie Inexistant',
        'feed_consumed'  => 80,
        'feed_unit_cost' => 0,
        'mortality'      => 0,
    ]);

    // Aucun stock CAT_CONSO portant ce nom n'est créé → angle mort.
    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('criticalTypes', fn ($ct) =>
            collect($ct)->contains(fn ($c) =>
                $c['type'] === 'Aliment Bergerie Inexistant' && $c['days'] === -1
            )
        )
        ->assertSee('MANQUANT');
});

test('les boutons actions rapides sont masqués pour un opérateur sans droits logistique', function () {
    $this->seed(Database\Seeders\ModuleSeeder::class);

    $role = Role::firstOrCreate(
        ['name' => 'operateur'],
        ['label' => 'Opérateur', 'display_name' => 'Opérateur', 'permissions' => ['L']]
    );
    $now = now();
    foreach (App\Models\Module::all() as $module) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $module->id],
            [
                'can_read'   => in_array($module->slug, ['elevage', 'dashboard']),
                'can_create' => false, 'can_modify' => false, 'can_delete' => false,
                'updated_at' => $now, 'created_at' => $now,
            ]
        );
    }
    $operateur = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($operateur)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Magasin Oeufs')
        ->assertDontSee('Provenderie')
        ->assertDontSee('Stocks')
        ->assertDontSee('Nouvelle Bande');
});

test('un lot virtuel (œufs externes) est exclu des KPI et de la liste du dashboard', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);

    // Lot physique réel.
    App\Models\Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'code'             => 'PHYS-001',
        'initial_quantity' => 1000,
        'current_quantity' => 980,
    ]);

    // Lot VIRTUEL d'œufs externes : initial_quantity = 0 (cf. StartIncubation).
    // Il ne doit ni gonfler les compteurs ni apparaître dans la liste.
    App\Models\Batch::factory()->create([
        'status'           => 'Actif',
        'code'             => 'EXT-AVICO',
        'initial_quantity' => 0,
        'current_quantity' => 0,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('activeLotsCount', 1)               // seul le lot physique compte
        ->assertViewHas('totalBirds', 980)
        ->assertViewHas('activeBatches', fn ($b) => $b->pluck('code')->doesntContain('EXT-AVICO'))
        ->assertSee('PHYS-001')
        ->assertDontSee('EXT-AVICO');
});

test('l\'endpoint hors-ligne des lots exclut les lots virtuels', function () {
    App\Models\Batch::factory()->create([
        'status' => 'Actif', 'code' => 'PHYS-XYZ',
        'initial_quantity' => 500, 'current_quantity' => 500,
    ]);
    App\Models\Batch::factory()->create([
        'status' => 'Actif', 'code' => 'EXT-FOURNISSEUR',
        'initial_quantity' => 0, 'current_quantity' => 0,
    ]);

    $response = $this->actingAs($this->adminUser)->getJson('/api/offline/batches');

    $response->assertOk();
    $codes = collect($response->json())->pluck('code');
    expect($codes)->toContain('PHYS-XYZ');
    expect($codes)->not->toContain('EXT-FOURNISSEUR');
});
