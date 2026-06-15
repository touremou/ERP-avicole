<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\DailyCheck;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
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
    $viewer = Role::firstOrCreate(
        ['name' => 'viewer'],
        ['label' => 'Viewer', 'display_name' => 'Viewer', 'permissions' => ['L']]
    );

    $now = now();
    foreach ([[$manager, true], [$viewer, false]] as [$role, $write]) {
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['can_read' => true, 'can_create' => $write, 'can_modify' => $write, 'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
    $this->viewerUser  = User::factory()->create(['role_id' => $viewer->id]);
    $this->building    = Building::factory()->create(['type' => 'chair', 'capacity' => 5000]);
});

test('un visiteur (L) ne peut PAS enregistrer un pointage', function () {
    $batch = Batch::factory()->create(['building_id' => $this->building->id, 'status' => 'Actif']);

    // L'app convertit AuthorizationException en redirection (cf. bootstrap/app.php) :
    // l'accès est refusé et aucun pointage n'est créé.
    $this->actingAs($this->viewerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'     => $batch->id,
            'check_date'   => now()->toDateString(),
            'mortality'    => 1,
            'feed_consumed' => 0,
            'feed_type'    => 'Chair Démarrage',
        ])
        ->assertRedirect();

    expect(DailyCheck::where('batch_id', $batch->id)->exists())->toBeFalse();
});

test('une rectification avec champs quarantaine vides est acceptée (défaut à 0)', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'           => $batch->id,
        'mortality'          => 0,
        'feed_consumed'      => 0,
        'feed_type'          => 'Chair Démarrage',
        'qty_quarantine_in'  => 0,
        'qty_quarantine_out' => 0,
        'qty_sorted_out'     => 0,
    ]);

    // Les champs quarantaine sont soumis VIDES (l'utilisateur a effacé la
    // valeur) : ils doivent retomber à 0, pas déclencher une erreur "requis".
    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'          => 2,
            'feed_consumed'      => 0,
            'feed_type'          => 'Chair Démarrage',
            'qty_quarantine_in'  => '',
            'qty_quarantine_out' => '',
        ])
        ->assertSessionDoesntHaveErrors();

    $check->refresh();
    expect($check->mortality)->toBe(2);
    expect($check->qty_quarantine_in)->toBe(0);
});

test('une rectification aquacole avec un pH hors bornes est rejetée', function () {
    $tilapia = Species::firstOrCreate(
        ['slug' => 'tilapia'],
        ['name_fr' => 'Tilapia', 'family' => 'aquaculture', 'is_active' => true, 'tracks_water_quality' => true]
    );

    $bassin = Building::factory()->create(['type' => 'bassin', 'capacity' => 5000]);

    $batch = Batch::factory()->create([
        'species_id'         => $tilapia->id,
        'building_id'        => $bassin->id,
        'status'             => 'Actif',
        'current_quantity'   => 1000,
        'production_type_id' => ProductionType::resolveOrCreate('grossissement', $tilapia->id)->id,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'      => $batch->id,
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Grossissement',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'          => 0,
            'feed_consumed'      => 0,
            'feed_type'          => 'Grossissement',
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
            'ext_water_ph'       => 15, // > 14 : impossible
        ])
        ->assertSessionHasErrors('ext_water_ph');
});

test('une rectification dont la mortalité dépasse l\'effectif est rejetée', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 3,
    ]);

    $check = DailyCheck::factory()->create([
        'batch_id'      => $batch->id,
        'mortality'     => 0,
        'feed_consumed' => 0,
        'feed_type'     => 'Chair Démarrage',
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('daily-checks.update', $check), [
            'mortality'          => 999,
            'feed_consumed'      => 0,
            'feed_type'          => 'Chair Démarrage',
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
        ])
        ->assertSessionHasErrors('mortality');
});
