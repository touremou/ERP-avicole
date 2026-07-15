<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Module;
use App\Models\Role;
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
    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
});

test('créer un bâtiment redirige vers l\'index', function () {
    $this->actingAs($this->managerUser)
        ->post(route('buildings.store'), [
            'name'     => 'Hangar Test X',
            'type'     => 'chair',
            'surface'  => 200,
            'capacity' => 3000,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('buildings.index'));

    expect(Building::where('name', 'Hangar Test X')->exists())->toBeTrue();
});

test('réduire la capacité sous l\'effectif logé est refusé', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 1000, 'status' => Building::STATUS_OCCUPE]);
    Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'current_quantity' => 800,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('buildings.update', $building->id), [
            'name'     => $building->name,
            'type'     => 'chair',
            'surface'  => $building->surface,
            'capacity' => 100, // < 800 logés
            'status'   => Building::STATUS_OCCUPE,
        ])
        ->assertSessionHasErrors('capacity');

    expect($building->fresh()->capacity)->toBe(1000);
});

test('passer un bâtiment occupé au statut Disponible est refusé', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 1000, 'status' => Building::STATUS_OCCUPE]);
    Batch::factory()->create([
        'building_id'      => $building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('buildings.update', $building->id), [
            'name'     => $building->name,
            'type'     => 'chair',
            'surface'  => $building->surface,
            'capacity' => 1000,
            'status'   => Building::STATUS_DISPONIBLE,
        ])
        ->assertSessionHasErrors('status');
});

test('le statut Maintenance est accepté pour un bâtiment vide', function () {
    $building = Building::factory()->create(['type' => 'chair', 'capacity' => 1000, 'status' => Building::STATUS_VIDE]);

    $this->actingAs($this->managerUser)
        ->put(route('buildings.update', $building->id), [
            'name'     => $building->name,
            'type'     => 'chair',
            'surface'  => $building->surface,
            'capacity' => 1000,
            'status'   => Building::STATUS_MAINTENANCE,
        ])
        ->assertSessionDoesntHaveErrors();

    expect($building->fresh()->status)->toBe(Building::STATUS_MAINTENANCE);
});
