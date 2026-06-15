<?php

use App\Models\Building;
use App\Models\Module;
use App\Models\PlannedBatch;
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

    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $manager->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
});

test('la planification d\'une bande caprine dans un bâtiment volaille est refusée', function () {
    $chevre = Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $chairBuilding = Building::factory()->create(['type' => 'chair']);

    $this->actingAs($this->managerUser)
        ->post(route('planning.store'), [
            'building_id'          => $chairBuilding->id,
            'batch_type'           => 'engraissement',
            'species_id'           => $chevre->id,
            'planned_quantity'     => 10,
            'planned_arrival_date' => now()->addDays(5)->toDateString(),
        ])
        ->assertSessionHasErrors('building_id');

    expect(PlannedBatch::where('building_id', $chairBuilding->id)->exists())->toBeFalse();
});

test('la planification d\'une bande caprine dans une chèvrerie est acceptée', function () {
    $chevre = Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $chevrerie = Building::factory()->create(['type' => 'chevrerie', 'capacity' => 100]);

    $this->actingAs($this->managerUser)
        ->post(route('planning.store'), [
            'building_id'          => $chevrerie->id,
            'batch_type'           => 'engraissement',
            'species_id'           => $chevre->id,
            'planned_quantity'     => 10,
            'planned_arrival_date' => now()->addDays(5)->toDateString(),
        ])
        ->assertSessionDoesntHaveErrors();

    expect(PlannedBatch::where('building_id', $chevrerie->id)->exists())->toBeTrue();
});
