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

    // Sans note de résolution → refus (traçabilité obligatoire).
    $this->actingAs($this->adminUser)
        ->patch(route('health.incidents.resolve', $incident), [])
        ->assertSessionHasErrors('resolution_notes');

    $this->actingAs($this->adminUser)
        ->patch(route('health.incidents.resolve', $incident), [
            'resolution_notes' => 'Traitement terminé, mortalité normalisée.',
        ])
        ->assertSessionHas('success');

    $fresh = $incident->fresh();
    expect($fresh->status)->toBe('resolu')
        ->and($fresh->resolved_by)->toBe($this->adminUser->id)
        ->and($fresh->resolved_at)->not->toBeNull();
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

test('l\'incident est rattaché au LOT et porte la gravité saisie (déclaration sans erreur)', function () {
    // La déclaration déclenche une alerte best-effort (try/catch) : elle ne doit
    // jamais faire échouer l'enregistrement.
    $this->actingAs($this->adminUser)
        ->post(route('health.incidents.store'), [
            'batch_id'        => $this->batch->id,
            'mortality_count' => 5,
            'severity'        => 'critique',
            'symptoms'        => 'Mortalité subite, fientes vertes',
        ])
        ->assertSessionHas('success');

    $incident = HealthIncident::first();
    expect($incident->batch_id)->toBe($this->batch->id)
        ->and($incident->building_id)->toBe($this->building->id)
        ->and($incident->severity)->toBe('critique')
        ->and($incident->status)->toBe('en_attente');
});

test('le diagnostic trace l\'auteur et impute le coût de traitement', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 2, 'symptoms' => 'Toux', 'status' => 'en_attente',
    ]);

    $this->actingAs($this->adminUser)
        ->put(route('health.incidents.diagnose', $incident), [
            'suspected_disease' => 'Colibacillose',
            'treatment_cost'    => 75000,
        ])
        ->assertSessionHas('success');

    $fresh = $incident->fresh();
    expect($fresh->status)->toBe('diagnostique')
        ->and($fresh->diagnosed_by)->toBe($this->adminUser->id)
        ->and($fresh->diagnosed_at)->not->toBeNull()
        ->and((float) $fresh->treatment_cost)->toBe(75000.0);
});

test('la quarantaine s\'active puis se lève sur un incident', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 1, 'symptoms' => 'Suspicion', 'status' => 'en_attente',
    ]);

    $this->actingAs($this->adminUser)->patch(route('health.incidents.quarantine', $incident));
    expect($incident->fresh()->is_quarantined)->toBeTrue();

    $this->actingAs($this->adminUser)->patch(route('health.incidents.quarantine', $incident));
    $fresh = $incident->fresh();
    expect($fresh->is_quarantined)->toBeFalse()
        ->and($fresh->quarantine_ended_at)->not->toBeNull();
});

test('un incident peut être rattaché au pointage qui l\'a révélé (sans double comptage de mortalité)', function () {
    $check = App\Models\DailyCheck::create([
        'farm_id' => $this->farm->id, 'batch_id' => $this->batch->id,
        'check_date' => now()->toDateString(), 'mortality' => 4,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('health.incidents.store'), [
            'batch_id'       => $this->batch->id,
            'daily_check_id' => $check->id,
            'mortality_count' => 4,
            'symptoms'       => 'Mortalité anormale relevée au pointage',
        ])
        ->assertSessionHas('success');

    $incident = HealthIncident::first();
    expect($incident->daily_check_id)->toBe($check->id)
        ->and($incident->batch_id)->toBe($this->batch->id);

    // La mortalité du lot reste pilotée par le pointage (l'incident est qualitatif).
    expect($this->batch->healthIncidents()->where('status', '!=', 'resolu')->count())->toBe(1);
});
