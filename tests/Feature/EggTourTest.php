<?php

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\HealthIncident;
use App\Models\ProductionType;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Feuille de TOURNÉE de collecte multi-lots (audit UX 2026-07-03).
 *
 * Une ligne par bande pondeuse active EN ÂGE de ponte ; chaque ligne saisie
 * passe par RecordEggCollection (mêmes invariants que la saisie unitaire :
 * cumul du jour, âge, quarantaine, plafond 100 %). Les lignes refusées
 * n'annulent pas les lignes valides.
 */

beforeEach(function () {
    $this->setUpRbac();

    $ponte = ProductionType::resolveOrCreate('ponte', null);
    $ponte->update(['metrics_enabled' => ['eggs' => true]]);

    $chair = ProductionType::resolveOrCreate('chair', null);

    // Deux bandes pondeuses en âge (effectif 200), une trop jeune, une chair.
    $this->bandeA = Batch::factory()->create([
        'code' => 'PONTE-A', 'production_type_id' => $ponte->id,
        'arrival_date' => now()->subDays(200),
        'initial_quantity' => 200, 'current_quantity' => 200, 'qty_alive' => 200,
    ]);
    $this->bandeB = Batch::factory()->create([
        'code' => 'PONTE-B', 'production_type_id' => $ponte->id,
        'arrival_date' => now()->subDays(220),
        'initial_quantity' => 200, 'current_quantity' => 200, 'qty_alive' => 200,
    ]);
    $this->tropJeune = Batch::factory()->create([
        'code' => 'PONTE-JEUNE', 'production_type_id' => $ponte->id,
        'arrival_date' => now()->subDays(30),
        'initial_quantity' => 200, 'current_quantity' => 200, 'qty_alive' => 200,
    ]);
    $this->chair = Batch::factory()->create([
        'code' => 'CHAIR-X', 'production_type_id' => $chair->id,
        'arrival_date' => now()->subDays(200),
        'initial_quantity' => 200, 'current_quantity' => 200, 'qty_alive' => 200,
    ]);
});

test('la feuille liste les bandes pondeuses en âge — pas les jeunes ni les chairs', function () {
    $this->actingAs($this->managerUser)
        ->get(route('egg-productions.tour'))
        ->assertOk()
        ->assertSee('PONTE-A')
        ->assertSee('PONTE-B')
        ->assertDontSee('PONTE-JEUNE')
        ->assertDontSee('CHAIR-X');
});

test('la tournée enregistre plusieurs collectes en un envoi (alvéoles × 30 + unités)', function () {
    $this->actingAs($this->managerUser)
        ->post(route('egg-productions.tour.store'), [
            'lines' => [
                ['batch_id' => $this->bandeA->id, 'trays' => 3, 'units' => 10], // 100
                ['batch_id' => $this->bandeB->id, 'trays' => 2, 'units' => 0],  // 60
            ],
        ])
        ->assertRedirect(route('egg-productions.tour'))
        ->assertSessionHas('success');

    $prodA = EggProduction::where('batch_id', $this->bandeA->id)->first();
    $prodB = EggProduction::where('batch_id', $this->bandeB->id)->first();

    expect($prodA->total_eggs_collected)->toBe(100);
    expect((float) $prodA->laying_rate)->toEqual(50.0); // 100 / 200
    expect($prodB->total_eggs_collected)->toBe(60);
});

test('les lignes vides sont ignorées sans erreur', function () {
    $this->actingAs($this->managerUser)
        ->post(route('egg-productions.tour.store'), [
            'lines' => [
                ['batch_id' => $this->bandeA->id, 'trays' => 1, 'units' => 0],
                ['batch_id' => $this->bandeB->id, 'trays' => 0, 'units' => 0], // non saisie
            ],
        ])
        ->assertSessionHas('success', '1 collecte(s) enregistrée(s).');

    expect(EggProduction::where('batch_id', $this->bandeB->id)->exists())->toBeFalse();
});

test('ligne forgée sur un lot en quarantaine : refusée — les lignes valides passent quand même', function () {
    HealthIncident::create([
        'building_id'           => $this->bandeB->building_id,
        'batch_id'              => $this->bandeB->id,
        'user_id'               => $this->managerUser->id,
        'incident_date'         => now()->toDateString(),
        'mortality_count'       => 5,
        'symptoms'              => 'Chute de ponte brutale',
        'severity'              => HealthIncident::SEVERITY_CRITICAL,
        'status'                => HealthIncident::STATUS_PENDING,
        'is_quarantined'        => true,
        'quarantine_started_at' => now(),
    ]);

    // La feuille désactive la saisie côté vue ; on force la ligne côté requête.
    $this->actingAs($this->managerUser)
        ->post(route('egg-productions.tour.store'), [
            'lines' => [
                ['batch_id' => $this->bandeA->id, 'trays' => 1, 'units' => 0],
                ['batch_id' => $this->bandeB->id, 'trays' => 1, 'units' => 0],
            ],
        ])
        ->assertSessionHas('success')
        ->assertSessionHas('error');

    expect(EggProduction::where('batch_id', $this->bandeA->id)->exists())->toBeTrue();
    expect(EggProduction::where('batch_id', $this->bandeB->id)->exists())->toBeFalse();
    expect(session('error'))->toContain('PONTE-B');
});

test('seconde tournée le même jour : les quantités se cumulent (pas de doublon)', function () {
    foreach ([1, 2] as $passage) {
        $this->actingAs($this->managerUser)
            ->post(route('egg-productions.tour.store'), [
                'lines' => [['batch_id' => $this->bandeA->id, 'trays' => 1, 'units' => 5]], // 35
            ])
            ->assertSessionHas('success');
    }

    $rows = EggProduction::where('batch_id', $this->bandeA->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->total_eggs_collected)->toBe(70);
});
