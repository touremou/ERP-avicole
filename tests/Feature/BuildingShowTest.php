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

test('un bâtiment SANS surface affiche sa fiche (densité « non renseignée », pas une erreur 500)', function () {
    // La surface castée en décimal arrive en chaîne « 0.00 », vraie pour PHP :
    // `capacity / (surface ?: 1)` divisait par zéro.
    $sansSurface = \App\Models\Building::factory()->create(['surface' => 0, 'capacity' => 2000]);

    $this->get(route('buildings.show', $sansSurface))
        ->assertOk()
        ->assertSee(__('Surface non renseignée'));
});

test('le taux de mortalité d’un lot est le même sur la fiche bâtiment que sur sa fiche', function () {
    // La fiche bâtiment recalculait le taux à sa façon — sans la mortalité en
    // infirmerie, et sur un autre dénominateur que Batch::mortality_rate.
    $batiment = \App\Models\Building::factory()->create(['type' => 'mixte', 'capacity' => 2000]);
    $lot = \App\Models\Batch::factory()->create([
        'building_id' => $batiment->id, 'status' => 'Actif',
        'initial_quantity' => 1000, 'current_quantity' => 950, 'qty_dead' => 20,
    ]);
    \App\Models\DailyCheck::factory()->create([
        'batch_id' => $lot->id, 'check_date' => today()->toDateString(),
        'mortality' => 30, 'mortality_infirmary' => 10,
    ]);

    $attendu = number_format($lot->fresh()->mortality_rate, 1);   // (20+30+10)/(1000+20) = 5,9 %

    $page = $this->get(route('buildings.show', $batiment))->assertOk()->getContent();

    expect($attendu)->toBe('5.9')
        ->and($page)->toContain($attendu);
});
