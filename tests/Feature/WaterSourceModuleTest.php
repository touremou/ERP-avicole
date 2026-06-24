<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $manager = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]);
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $manager->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }
    $this->manager = User::factory()->create(['role_id' => $manager->id]);
});

function citerne(int $farmId, array $attrs = []): WaterSource
{
    return WaterSource::create(array_merge([
        'farm_id' => $farmId, 'name' => 'Citerne A', 'type' => 'citerne',
        'capacity_liters' => 1000, 'current_level_liters' => 800, 'current_level_percent' => 80, 'is_active' => true,
    ], $attrs));
}

test('refreshLevel ne dépasse jamais la capacité (anti-débordement)', function () {
    $src = citerne($this->farm->id, ['current_level_liters' => 800]);

    WaterReading::create([
        'farm_id' => $this->farm->id, 'water_source_id' => $src->id, 'user_id' => $this->manager->id,
        'reading_date' => now()->toDateString(), 'volume_consumed_liters' => 0, 'volume_added_liters' => 5000,
    ]);

    $src->refreshLevel();

    expect((float) $src->fresh()->current_level_liters)->toBe(1000.0)
        ->and((float) $src->fresh()->current_level_percent)->toBe(100.0);
});

test('la page d\'édition affiche le formulaire de MODIFICATION (bug corrigé)', function () {
    $src = citerne($this->farm->id, ['name' => 'Citerne Nord']);

    $this->actingAs($this->manager)
        ->get(route('utilities.water.sources.edit', $src->id))
        ->assertOk()
        ->assertSee('Modifier la source', false)
        ->assertSee(route('utilities.water.sources.update', $src->id), false) // le form pointe vers UPDATE
        ->assertSee('value="Citerne Nord"', false);                          // champ pré-rempli
});

test('mettre à jour une source fonctionne et recale le niveau si la capacité baisse', function () {
    $src = citerne($this->farm->id, ['capacity_liters' => 1000, 'current_level_liters' => 900, 'current_level_percent' => 90]);

    $this->actingAs($this->manager)
        ->put(route('utilities.water.sources.update', $src->id), [
            'name' => 'Citerne MAJ', 'type' => 'citerne', 'capacity_liters' => 500, 'is_active' => 1,
        ])
        ->assertRedirect(route('utilities.water.sources'));

    $src->refresh();
    expect($src->name)->toBe('Citerne MAJ')
        ->and((float) $src->capacity_liters)->toBe(500.0)
        ->and((float) $src->current_level_liters)->toBe(500.0); // recalé à la nouvelle capacité
});
