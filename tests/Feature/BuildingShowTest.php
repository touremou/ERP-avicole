<?php

use App\Models\Batch;
use App\Models\Building;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);
});

test('un bâtiment « Disponible » et vide propose de lancer un lot', function () {
    $building = Building::factory()->create(['status' => 'Disponible', 'capacity' => 1000]);

    $this->get(route('buildings.show', $building))
        ->assertOk()
        ->assertSee(route('batches.create', ['building_id' => $building->id]));
});

test('un bâtiment occupé ne propose pas de lancer un lot', function () {
    $building = Building::factory()->create(['status' => 'Occupé', 'capacity' => 1000]);
    Batch::factory()->create([
        'building_id' => $building->id, 'status' => 'Actif',
        'initial_quantity' => 500, 'current_quantity' => 500,
    ]);

    $this->get(route('buildings.show', $building))
        ->assertOk()
        ->assertDontSee(route('batches.create', ['building_id' => $building->id]));
});

test('le compte à rebours du vide sanitaire suit la date de désinfection, pas updated_at', function () {
    $building = Building::factory()->create([
        'status' => 'En désinfection',
        'disinfection_started_at' => now()->subDays(4), // 14 - 4 = 10 jours restants
    ]);

    // L'accesseur (source de vérité) doit donner ~10 jours, indépendamment de updated_at.
    expect($building->sanitary_break_remaining_days)->toBeGreaterThanOrEqual(9)
        ->and($building->sanitary_break_remaining_days)->toBeLessThanOrEqual(10);

    $this->get(route('buildings.show', $building))
        ->assertOk()
        ->assertSee('Vide Sanitaire');
});
