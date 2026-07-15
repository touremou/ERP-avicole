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

test('la fiche détail d\'un incident affiche sa chronologie', function () {
    $incident = HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 3, 'symptoms' => 'Diarrhée', 'status' => 'diagnostique',
        'severity' => 'critique', 'suspected_disease' => 'Coccidiose',
        'diagnosed_by' => $this->adminUser->id, 'diagnosed_at' => now(), 'treatment_cost' => 12000,
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('health.incidents.show', $incident))
        ->assertOk()
        ->assertSee('Coccidiose')
        ->assertSee('Chronologie')
        ->assertSee('Critique');
});

test('l\'index expose les KPIs sanitaires', function () {
    // 1 critique en attente + 1 résolu (hors « ouverts »).
    HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 5, 'symptoms' => 'X', 'status' => 'en_attente', 'severity' => 'critique',
    ]);
    HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 1, 'symptoms' => 'Y', 'status' => 'resolu', 'severity' => 'mineur', 'treatment_cost' => 8000,
    ]);

    $stats = $this->actingAs($this->adminUser)->get(route('health.incidents.index'))->assertOk()->viewData('stats');

    expect($stats['open'])->toBe(1)
        ->and($stats['critical'])->toBe(1)
        ->and((float) $stats['cost'])->toBe(8000.0);
});

test('le coût de traitement d\'un incident impacte la marge nette du lot', function () {
    $marginBefore = (float) $this->batch->fresh()->net_margin;

    HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->toDateString(),
        'mortality_count' => 2, 'symptoms' => 'X', 'status' => 'diagnostique',
        'suspected_disease' => 'Coccidiose', 'treatment_cost' => 40000,
    ]);

    // La marge baisse exactement du coût de traitement (santé) imputé au lot.
    expect((float) $this->batch->fresh()->net_margin)->toBe($marginBefore - 40000);
});

test('le rapport sanitaire agrège les incidents par maladie et gravité', function () {
    HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->subDays(5)->toDateString(),
        'mortality_count' => 6, 'symptoms' => 'X', 'status' => 'diagnostique',
        'severity' => 'critique', 'suspected_disease' => 'Newcastle', 'treatment_cost' => 30000,
    ]);
    HealthIncident::create([
        'farm_id' => $this->farm->id, 'building_id' => $this->building->id, 'batch_id' => $this->batch->id,
        'user_id' => $this->adminUser->id, 'incident_date' => now()->subDays(2)->toDateString(),
        'mortality_count' => 1, 'symptoms' => 'Y', 'status' => 'en_attente', 'severity' => 'mineur',
    ]);

    $resp = $this->actingAs($this->adminUser)->get(route('reports.health_incidents'))->assertOk();

    $summary = $resp->viewData('summary');
    expect($summary['total'])->toBe(2)
        ->and($summary['open'])->toBe(2) // diagnostique + en_attente = non résolus
        ->and($summary['mortality'])->toBe(7)
        ->and((float) $summary['cost'])->toBe(30000.0);

    expect($resp->viewData('byDisease')->has('Newcastle'))->toBeTrue()
        ->and($resp->viewData('byDisease')->has('Non diagnostiqué'))->toBeTrue();
    $resp->assertSee('Newcastle');
});
