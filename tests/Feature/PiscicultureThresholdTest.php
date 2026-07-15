<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $manager = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M']]
    );
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $manager->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
        );
    }
    $this->manager = User::factory()->create(['role_id' => $manager->id]);

    $tilapia = Species::firstOrCreate(
        ['slug' => 'tilapia'],
        ['name_fr' => 'Tilapia', 'family' => 'aquaculture', 'is_active' => true, 'tracks_water_quality' => true]
    );
    $bassin = Building::factory()->create(['type' => 'bassin', 'capacity' => 5000]);
    $this->batch = Batch::factory()->create([
        'species_id'         => $tilapia->id,
        'building_id'        => $bassin->id,
        'status'             => 'Actif',
        'current_quantity'   => 1000,
        'production_type_id' => ProductionType::resolveOrCreate('grossissement', $tilapia->id)->id,
    ]);
});

function createPage(): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->manager)
        ->get(route('daily-checks.create', ['batch_id' => test()->batch->id]));
}

test('les seuils qualité d\'eau affichés proviennent des paramètres (valeurs par défaut)', function () {
    createPage()
        ->assertOk()
        ->assertSee('PH_MIN = 6.5', false)
        ->assertSee('PH_MAX = 8.5', false)
        ->assertSee('O2_MIN = 4', false)
        ->assertSee('NH3_MAX = 1', false);
});

test('changer un paramètre pisciculture change le seuil rendu (plus de hard-code)', function () {
    Setting::set('pisciculture.ph_max', 9);
    Setting::set('pisciculture.ammonia_alert', 2);
    Setting::set('pisciculture.o2_alert', 5);

    createPage()
        ->assertOk()
        ->assertSee('PH_MAX = 9', false)
        ->assertSee('NH3_MAX = 2', false)
        ->assertSee('O2_MIN = 5', false)
        ->assertDontSee('PH_MAX = 8.5', false)  // l'ancien hard-code a disparu
        ->assertDontSee('NH3_MAX = 1', false);
});

test('le formulaire EDIT câble aussi les seuils et masque la litière pour un poisson', function () {
    $check = DailyCheck::factory()->create([
        'batch_id' => $this->batch->id, 'mortality' => 0, 'feed_consumed' => 0, 'feed_type' => 'Grossissement',
    ]);
    Setting::set('pisciculture.ph_max', 9);

    $this->actingAs($this->manager)->get(route('daily-checks.edit', $check))
        ->assertOk()
        ->assertSee('PH_MAX = 9', false)         // seuil paramétré rendu
        ->assertDontSee('ph > 8.5', false)       // plus de hard-code
        ->assertDontSee('Litière Changée');      // section avicole masquée pour le poisson
});
