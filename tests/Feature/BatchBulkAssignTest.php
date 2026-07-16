<?php

use App\Models\Batch;
use App\Models\Employee;
use App\Models\ProductionType;
use App\Models\Species;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function chairBatch(int $farmId): Batch
{
    $species = Species::firstOrCreate(
        ['slug' => 'poulet-chair'],
        ['name_fr' => 'Poulet de chair', 'family' => 'volaille', 'is_active' => true]
    );
    $type = ProductionType::resolveOrCreate('chair', $species->id);

    return Batch::factory()->create([
        'farm_id'            => $farmId,
        'production_type_id' => $type->id,
        'status'             => 'Actif',
    ]);
}

test('la liste des lots se rend avec la barre d’affectation en masse', function () {
    chairBatch($this->farm->id);

    $this->actingAs($this->adminUser)
        ->get(route('batches.index'))
        ->assertOk()
        ->assertSee('bulk-assign', false); // l'action du formulaire d'affectation
});

test('affectation en masse d’un responsable à plusieurs lots', function () {
    $b1 = chairBatch($this->farm->id);
    $b2 = chairBatch($this->farm->id);
    $employee = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('batches.bulkAssign'), [
            'batch_ids'   => [$b1->id, $b2->id],
            'employee_id' => $employee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($b1->fresh()->employee_id)->toBe($employee->id)
        ->and($b2->fresh()->employee_id)->toBe($employee->id);
});

test('on peut RETIRER le responsable en masse (employee_id vide)', function () {
    $employee = Employee::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);
    $batch = chairBatch($this->farm->id);
    $batch->update(['employee_id' => $employee->id]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('batches.bulkAssign'), [
            'batch_ids'   => [$batch->id],
            'employee_id' => '',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($batch->fresh()->employee_id)->toBeNull();
});
