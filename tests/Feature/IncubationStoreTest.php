<?php

use App\Models\Batch;
use App\Models\Incubator;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('le lancement d\'une incubation (source interne) réussit sans erreur 500', function () {
    $incubator = Incubator::create([
        'farm_id'  => $this->farm->id,
        'name'     => 'Couveuse A',
        'capacity' => 500,
        'status'   => 'Disponible',
    ]);
    $batch = Batch::factory()->create(['farm_id' => $this->farm->id, 'status' => 'Actif']);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('incubations.store'), [
            'incubator_id' => $incubator->id,
            'start_date'   => now()->toDateString(),
            'eggs_count'   => 300,
            'source_type'  => 'internal',
            'batch_id'     => $batch->id,
            'duration'     => 21,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('incubations', [
        'incubator_id' => $incubator->id,
        'batch_id'     => $batch->id,
        'eggs_count'   => 300,
    ]);
});

test('le lancement d\'une incubation (source externe, nouveau fournisseur) réussit sans 500', function () {
    $incubator = Incubator::create([
        'farm_id'  => $this->farm->id,
        'name'     => 'Couveuse B',
        'capacity' => 500,
        'status'   => 'Disponible',
    ]);

    $this->actingAs($this->adminUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('incubations.store'), [
            'incubator_id'       => $incubator->id,
            'start_date'         => now()->toDateString(),
            'eggs_count'         => 200,
            'source_type'        => 'external',
            'provider_id'        => 'new',
            'new_provider_name'  => 'Ferme Voisine',
            'new_provider_phone' => '620111222',
            'new_provider_type'  => 'Poussins',
            'duration'           => 21,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('incubations', ['incubator_id' => $incubator->id, 'eggs_count' => 200]);
});
