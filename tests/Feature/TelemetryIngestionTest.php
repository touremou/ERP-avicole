<?php

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\TelemetryLog;
use App\Models\TelemetrySensor;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Ingestion IoT découplée + température hybride (exigences pré-MEP 2 & 3).
 *
 * - Endpoint générique POST /api/v1/telemetry/temperature : clé d'API,
 *   contrat strict (sensor_id/timestamp ISO/value/unit), écrêtage anti-spam,
 *   écriture en ZONE TAMPON uniquement (telemetry_logs).
 * - Worker telemetry:process : association au lot actif (bâtiment + heure).
 * - Pointage : bornes anti fat-finger, source tracée (iot/manuel + auteur),
 *   la saisie manuelle prime mais un écart capteur > 2 °C alerte (calibration).
 */

beforeEach(function () {
    config(['services.telemetry.api_key' => 'test-key-123']);

    $this->setUpRbac();
    $this->setUpBaseData();

    $this->batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'arrival_date'     => now()->subDays(10),
        'initial_quantity' => 500, 'current_quantity' => 500, 'qty_alive' => 500,
    ]);

    $this->sensor = TelemetrySensor::create([
        'farm_id'     => $this->farm->id,
        'sensor_id'   => 'TH-001',
        'building_id' => $this->building->id,
        'label'       => 'Sonde bâtiment A',
    ]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'sensor_id' => 'TH-001',
        'timestamp' => now()->toIso8601String(),
        'value'     => 27.5,
        'unit'      => 'celsius',
    ], $overrides);
});

// ─── ENDPOINT (contrat + sécurité) ───

test('sans clé d\'API valide : 401 ; clé non configurée : 503 (désactivé par défaut)', function () {
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)())
        ->assertStatus(401);

    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(), ['X-Api-Key' => 'mauvaise'])
        ->assertStatus(401);

    config(['services.telemetry.api_key' => '']);
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(), ['X-Api-Key' => 'x'])
        ->assertStatus(503);

    expect(TelemetryLog::count())->toBe(0);
});

test('payload conforme : 201, relevé en zone tampon avec lieu résolu — AUCUNE écriture métier', function () {
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(), ['X-Api-Key' => 'test-key-123'])
        ->assertStatus(201)
        ->assertJson(['status' => 'accepted']);

    $log = TelemetryLog::first();
    expect((float) $log->value)->toEqual(27.5);
    expect($log->building_id)->toBe($this->building->id);
    expect($log->status)->toBe(TelemetryLog::STATUS_PENDING);
    expect($log->batch_id)->toBeNull(); // l'association lot = travail du worker

    expect(DailyCheck::count())->toBe(0); // découplage total
    expect($this->sensor->fresh()->last_seen_at)->not->toBeNull();
});

test('contrat violé (unité inconnue, valeur aberrante, timestamp invalide) : 422', function () {
    $h = ['X-Api-Key' => 'test-key-123'];

    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(['unit' => 'fahrenheit']), $h)->assertStatus(422);
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(['value' => 220]), $h)->assertStatus(422);
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(['timestamp' => 'pas-une-date']), $h)->assertStatus(422);

    expect(TelemetryLog::count())->toBe(0);
});

test('anti-spam : relevé rapproché sans variation significative → 202 throttled, non stocké', function () {
    $h = ['X-Api-Key' => 'test-key-123'];

    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(), $h)->assertStatus(201);

    // 30 s plus tard, +0,1 °C : aucune information → écrêté.
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)([
        'timestamp' => now()->addSeconds(30)->toIso8601String(),
        'value'     => 27.6,
    ]), $h)->assertStatus(202)->assertJson(['status' => 'throttled']);

    // 30 s plus tard mais +2 °C : variation significative → accepté malgré l'intervalle.
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)([
        'timestamp' => now()->addSeconds(60)->toIso8601String(),
        'value'     => 29.6,
    ]), $h)->assertStatus(201);

    expect(TelemetryLog::count())->toBe(2);
});

// ─── WORKER (association lot par lieu + heure) ───

test('telemetry:process associe le relevé au lot actif du bâtiment ; capteur inconnu → orphan', function () {
    $h = ['X-Api-Key' => 'test-key-123'];
    $this->postJson(route('api.v1.telemetry.temperature'), ($this->payload)(), $h)->assertStatus(201);

    // Relevé d'un capteur hors registre (matériel non déclaré).
    TelemetryLog::create([
        'sensor_id' => 'INCONNU-9', 'metric' => 'temperature', 'value' => 25,
        'unit' => 'celsius', 'recorded_at' => now(), 'status' => 'pending', 'created_at' => now(),
    ]);

    $this->artisan('telemetry:process')->assertSuccessful();

    $linked = TelemetryLog::where('sensor_id', 'TH-001')->first();
    expect($linked->status)->toBe(TelemetryLog::STATUS_LINKED);
    expect($linked->batch_id)->toBe($this->batch->id);

    expect(TelemetryLog::where('sensor_id', 'INCONNU-9')->first()->status)
        ->toBe(TelemetryLog::STATUS_ORPHAN);
});

// ─── POINTAGE (fat-finger, source, calibration) ───

test('fat-finger : température manuelle hors bornes physiques (220 °C) refusée', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id'      => $this->batch->id,
            'check_date'    => now()->toDateString(),
            'mortality'     => 0,
            'feed_consumed' => 0,
            'feed_type'     => 'Chair Démarrage',
            'temp_min'      => 22,
            'temp_max'      => 220, // 22.0 mal tapé sur tablette gantée
        ])
        ->assertSessionHasErrors('temp_max');

    expect(DailyCheck::count())->toBe(0);
});

test('source tracée : pointage IoT porte capteur ; manuel porte l\'opérateur', function () {
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id' => $this->batch->id, 'check_date' => now()->toDateString(),
            'mortality' => 0, 'feed_consumed' => 0, 'feed_type' => 'Chair Démarrage',
            'temp_min' => 26.5, 'temp_max' => 28.0,
            'temp_source' => 'iot', 'temp_recorded_by' => 'TH-001',
        ])->assertSessionHasNoErrors();

    $iotCheck = DailyCheck::latest('id')->first();
    expect($iotCheck->temp_source)->toBe('iot');
    expect($iotCheck->temp_recorded_by)->toBe('TH-001');

    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id' => $this->batch->id, 'check_date' => now()->subDay()->toDateString(),
            'mortality' => 0, 'feed_consumed' => 0, 'feed_type' => 'Chair Démarrage',
            'temp_min' => 25, 'temp_max' => 27,
        ])->assertSessionHasNoErrors();

    $manualCheck = DailyCheck::latest('id')->first();
    expect($manualCheck->temp_source)->toBe('manuel');
    expect($manualCheck->temp_recorded_by)->toBe($this->managerUser->name);
});

test('conflit IoT/manuel : la saisie manuelle prime mais l\'écart > 2 °C alerte (calibration)', function () {
    // Capteur du jour : 25,0 °C max.
    TelemetryLog::create([
        'farm_id' => $this->farm->id, 'sensor_id' => 'TH-001', 'metric' => 'temperature',
        'value' => 25.0, 'unit' => 'celsius', 'recorded_at' => now(),
        'building_id' => $this->building->id, 'status' => 'pending', 'created_at' => now(),
    ]);

    // L'opérateur saisit 32 °C à la main (écart 7 °C).
    $this->actingAs($this->managerUser)
        ->post(route('daily-checks.store'), [
            'batch_id' => $this->batch->id, 'check_date' => now()->toDateString(),
            'mortality' => 0, 'feed_consumed' => 0, 'feed_type' => 'Chair Démarrage',
            'temp_min' => 30, 'temp_max' => 32, 'temp_source' => 'manuel',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('warning');

    // La saisie manuelle est CONSERVÉE (elle prime).
    $check = DailyCheck::latest('id')->first();
    expect((float) $check->temp_max)->toEqual(32.0);
    expect(session('warning'))->toContain('calibration');
});
