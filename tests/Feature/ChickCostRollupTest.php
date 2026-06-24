<?php

use App\Actions\Incubation\StartIncubation;
use App\Models\Batch;
use App\Models\Building;
use App\Models\Farm;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $role = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]);
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }
    $this->manager = User::factory()->create(['role_id' => $role->id]);
});

test('chickUnitCost répartit le coût des œufs sur les poussins ÉCLOS (œufs perdus absorbés)', function () {
    $inc = new Incubation(['eggs_count' => 100, 'egg_unit_cost' => 500, 'hatched_chicks' => 80]);

    expect($inc->eggsTotalCost())->toBe(50000.0)
        ->and($inc->chickUnitCost())->toBe(625.0); // 50 000 / 80 éclos
});

test('chickUnitCost vaut 0 tant qu\'aucun poussin n\'est éclos', function () {
    $inc = new Incubation(['eggs_count' => 100, 'egg_unit_cost' => 500, 'hatched_chicks' => 0]);
    expect($inc->chickUnitCost())->toBe(0.0);
});

test('StartIncubation enregistre le coût unitaire de l\'œuf', function () {
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    $incubator = Incubator::create(['farm_id' => $this->farm->id, 'name' => 'Couveuse 1', 'capacity' => 500, 'status' => 'Disponible']);

    $inc = app(StartIncubation::class)->execute([
        'incubator_id' => $incubator->id, 'start_date' => now()->toDateString(),
        'eggs_count' => 200, 'egg_unit_cost' => 450, 'source_type' => 'internal',
        'batch_id' => $batch->id, 'duration' => 21,
    ]);

    expect((float) $inc->egg_unit_cost)->toBe(450.0);
});

test('REGRESSION coût : les poussins mis en élevage héritent du coût des œufs', function () {
    $source = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    $incubator = Incubator::create(['farm_id' => $this->farm->id, 'name' => 'Couveuse 1', 'capacity' => 500, 'status' => 'Occupé']);

    $inc = Incubation::create([
        'farm_id' => $this->farm->id, 'batch_id' => $source->id, 'incubator_id' => $incubator->id,
        'code_incubation' => 'INC-TEST-1', 'start_date' => now()->subDays(21), 'incubation_duration' => 21,
        'hatch_date_expected' => now(), 'eggs_count' => 100, 'egg_unit_cost' => 500,
        'fertile_eggs' => 90, 'hatched_chicks' => 80, 'status' => 'clos',
    ]);

    $building = Building::factory()->create(['farm_id' => $this->farm->id, 'type' => 'poussiniere', 'capacity' => 1000]);

    $this->actingAs($this->manager)
        ->post(route('chick-dispatches.store', $inc), [
            'destination_type' => 'elevage',
            'quantity'         => 50,
            'quality_grade'    => 'A',
            'building_id'      => $building->id,
        ])
        ->assertSessionHasNoErrors();

    $poussiniere = Batch::where('building_id', $building->id)->where('status', 'Actif')->latest('id')->first();

    // coût/poussin = 100 × 500 / 80 = 625 ; total = 50 × 625 = 31 250
    expect($poussiniere)->not->toBeNull()
        ->and((float) $poussiniere->buy_price_per_unit)->toBe(625.0)
        ->and((float) $poussiniere->total_acquisition_cost)->toBe(31250.0);
});
