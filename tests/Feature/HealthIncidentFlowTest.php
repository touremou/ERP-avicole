<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\HealthIncident;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->building = Building::factory()->create(['farm_id' => $this->farm->id]);
    $this->batch = Batch::factory()->create([
        'farm_id'     => $this->farm->id,
        'building_id' => $this->building->id,
        'status'      => 'actif',
    ]);
});

test('le signalement d\'une anomalie crée un incident en attente', function () {
    $this->actingAs($this->adminUser)
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 3,
            'symptoms'        => 'Diarrhée, plumes ébouriffées',
        ])
        ->assertSessionHas('success');

    $incident = HealthIncident::first();
    expect($incident)->not->toBeNull();
    expect($incident->status)->toBe('en_attente');
});

test('poser un diagnostic fait basculer l\'incident en diagnostiqué', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 2, 'symptoms' => 'Toux', 'status' => 'en_attente',
    ]);

    $this->actingAs($this->adminUser)
        ->put(route('health.incidents.diagnose', $incident), [
            'suspected_disease' => 'Coccidiose',
            'vet_prescription'  => 'Anticoccidien 5 jours',
        ])
        ->assertSessionHas('success');

    $incident->refresh();
    expect($incident->status)->toBe('diagnostique');
    expect($incident->suspected_disease)->toBe('Coccidiose');
});

test('marquer comme résolu clôture l\'incident diagnostiqué', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 2, 'symptoms' => 'Toux', 'status' => 'diagnostique',
        'suspected_disease' => 'Coccidiose',
    ]);

    $this->actingAs($this->adminUser)
        ->patch(route('health.incidents.resolve', $incident))
        ->assertSessionHas('success');

    expect($incident->fresh()->status)->toBe('resolu');
});

test('la clôture rapide exige une justification et marque cause non médicale', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 1, 'symptoms' => 'Trouvé mort', 'status' => 'en_attente',
    ]);

    // Sans justification → refus de validation
    $this->actingAs($this->adminUser)
        ->patch(route('health.incidents.closeFast', $incident), [])
        ->assertSessionHasErrors('justification');

    // Avec justification → clôturé
    $this->actingAs($this->adminUser)
        ->patch(route('health.incidents.closeFast', $incident), [
            'justification' => 'Étouffement accidentel (panne ventilation)',
        ])
        ->assertSessionHas('success');

    $incident->refresh();
    expect($incident->status)->toBe('resolu');
    expect($incident->suspected_disease)->toBe('Cause non médicale');
});

test('un lecteur seul ne peut pas poser de diagnostic', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 2, 'symptoms' => 'Toux', 'status' => 'en_attente',
    ]);

    $this->actingAs($this->readonlyUser)
        ->put(route('health.incidents.diagnose', $incident), [
            'suspected_disease' => 'Test',
        ])
        ->assertSessionHas('error');

    expect($incident->fresh()->status)->toBe('en_attente');
});
