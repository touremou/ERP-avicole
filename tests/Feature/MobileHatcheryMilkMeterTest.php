<?php

use App\Models\Batch;
use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\Incubation;
use App\Models\Incubator;
use App\Models\MilkProduction;
use App\Models\WaterReading;
use App\Models\WaterSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * M5 — Élevage avancé au terrain : mirage/éclosion EN SALLE d'incubation,
 * traite À LA CHÈVRERIE, relevés de compteurs DEVANT le compteur. Toutes les
 * règles web sont rejouées (taux calculés serveur, plafonds physiques,
 * carburant/coût estimés) et les ops sont idempotentes.
 */

beforeEach(function () {
    $this->setUpRbac();
    DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->managerUser);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);
});

function openIncubation(int $eggs = 500, ?int $fertile = null): Incubation
{
    $incubator = Incubator::create([
        'name' => 'Couveuse ' . Str::random(3), 'capacity' => 1000, 'status' => 'Disponible',
    ]);
    // Un cycle est rattaché au lot de reproducteurs d'origine (NOT NULL).
    $breeder = Batch::factory()->create(['code' => 'REPRO-' . Str::upper(Str::random(3))]);

    return Incubation::create([
        'batch_id' => $breeder->id,
        'incubator_id' => $incubator->id,
        'code_incubation' => 'INC-' . Str::upper(Str::random(4)),
        'start_date' => now()->subDays(18)->toDateString(),
        'incubation_duration' => 21,
        'hatch_date_expected' => now()->addDays(3)->toDateString(),
        'eggs_count' => $eggs,
        'fertile_eggs' => $fertile,
        'status' => $fertile === null ? 'incubation' : 'mirage_fait',
    ]);
}

test('mirage mobile : fertilité calculée serveur, statut mirage_fait', function () {
    $incubation = openIncubation(500);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'incubation.mirage',
        'payload' => ['uuid' => (string) Str::uuid(), 'incubation_id' => $incubation->id, 'fertile_eggs' => 425],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $fresh = $incubation->fresh();
    expect($fresh->status)->toBe('mirage_fait')
        ->and((int) $fresh->fertile_eggs)->toBe(425)
        // 425 / 500 = 85 %
        ->and((float) $fresh->fertility_rate)->toEqualWithDelta(85, 0.01);
});

test('mirage : plus d\'œufs fertiles que d\'œufs couvés → validation_failed', function () {
    $incubation = openIncubation(500);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'incubation.mirage',
        'payload' => ['uuid' => (string) Str::uuid(), 'incubation_id' => $incubation->id, 'fertile_eggs' => 600],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and($incubation->fresh()->status)->toBe('incubation');
});

test('mirage : idempotence par mirage_uuid', function () {
    $incubation = openIncubation(500);
    $op = ['op_uuid' => (string) Str::uuid(), 'type' => 'incubation.mirage',
        'payload' => ['uuid' => (string) Str::uuid(), 'incubation_id' => $incubation->id, 'fertile_eggs' => 400]];

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and((int) $incubation->fresh()->fertile_eggs)->toBe(400);
});

test('éclosion mobile : éclosabilité calculée, cycle clos, poussins à dispatcher', function () {
    $incubation = openIncubation(500, 425);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'incubation.hatch',
        'payload' => ['uuid' => (string) Str::uuid(), 'incubation_id' => $incubation->id, 'hatched_chicks' => 340],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $fresh = $incubation->fresh();
    expect($fresh->status)->toBe('clos')
        ->and((int) $fresh->hatched_chicks)->toBe(340)
        // 340 / 425 = 80 %
        ->and((float) $fresh->hatchability_rate)->toEqualWithDelta(80, 0.01)
        ->and((int) $fresh->chicks_remaining)->toBe(340)
        ->and((int) $fresh->chicks_dispatched)->toBe(0);
});

test('éclosion : plus de poussins que d\'œufs fertiles → validation_failed', function () {
    $incubation = openIncubation(500, 425);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'incubation.hatch',
        'payload' => ['uuid' => (string) Str::uuid(), 'incubation_id' => $incubation->id, 'hatched_chicks' => 500],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and($incubation->fresh()->status)->toBe('mirage_fait');
});

test('le pull ne descend que les cycles OUVERTS', function () {
    $open = openIncubation(300);
    $closed = openIncubation(200, 180);
    $closed->update(['status' => 'clos', 'hatched_chicks' => 150]);

    $codes = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.incubations.upserts'))
        ->pluck('code_incubation');

    expect($codes)->toContain($open->code_incubation)
        ->and($codes)->not->toContain($closed->code_incubation);
});

// ─── Traite ───

test('traite mobile : total maintenu (matin + soir) et valorisation', function () {
    $batch = Batch::factory()->create(['code' => 'CAPRIN-1', 'current_quantity' => 40, 'qty_alive' => 40]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'milk_production.create',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'batch_id' => $batch->id,
            'production_date' => now()->toDateString(),
            'morning_liters' => 18.5, 'evening_liters' => 14.5,
            'unit_price' => 8000, 'milking_females' => 22,
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $milk = MilkProduction::first();
    expect((float) $milk->total_liters)->toEqualWithDelta(33, 0.01)
        ->and((int) $milk->milking_females)->toBe(22)
        ->and($milk->recorded_by)->toBe($this->managerUser->id);
});

test('traite sans aucun litre → validation_failed', function () {
    $batch = Batch::factory()->create(['code' => 'CAPRIN-2', 'current_quantity' => 10, 'qty_alive' => 10]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'milk_production.create',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'batch_id' => $batch->id,
            'production_date' => now()->toDateString(),
            'morning_liters' => 0, 'evening_liters' => 0,
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('validation_failed')
        ->and(MilkProduction::count())->toBe(0);
});

test('traite : idempotence par uuid', function () {
    $batch = Batch::factory()->create(['code' => 'CAPRIN-3', 'current_quantity' => 10, 'qty_alive' => 10]);
    $op = ['op_uuid' => (string) Str::uuid(), 'type' => 'milk_production.create',
        'payload' => ['uuid' => (string) Str::uuid(), 'batch_id' => $batch->id,
            'production_date' => now()->toDateString(), 'morning_liters' => 12, 'evening_liters' => 0]];

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(MilkProduction::count())->toBe(1);
});

// ─── Relevés de compteurs ───

test('relevé énergie : gasoil et coût ESTIMÉS quand seules les heures sont saisies', function () {
    $source = EnergySource::create([
        'name' => 'Groupe 60 kVA', 'type' => 'groupe', 'fuel_type' => 'gasoil',
        'fuel_tank_capacity' => 200, 'current_fuel_level' => 150,
        'maintenance_interval_hours' => 250, 'status' => 'operationnel', 'is_active' => true,
    ]);
    // Historique pour que la conso horaire moyenne soit calculable.
    EnergyReading::create([
        'energy_source_id' => $source->id, 'reading_date' => now()->subDays(2)->toDateString(),
        'hours_run' => 10, 'fuel_consumed_liters' => 50, 'user_id' => $this->managerUser->id,
    ]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'energy_reading.create',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'energy_source_id' => $source->id,
            'reading_date' => now()->toDateString(), 'hours_run' => 6,
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $reading = EnergyReading::where('energy_source_id', $source->id)
        ->whereDate('reading_date', now()->toDateString())->first();
    // 5 L/h d'après l'historique × 6 h = 30 L estimés, puis coût dérivé.
    expect((float) $reading->fuel_consumed_liters)->toEqualWithDelta(30, 0.5)
        ->and((float) $reading->cost)->toBeGreaterThan(0)
        // Niveau de carburant décrémenté par la consommation estimée.
        ->and((float) $source->fresh()->current_fuel_level)->toEqualWithDelta(120, 0.5);
});

test('relevé énergie : un rejeu met à jour la même ligne (source + jour)', function () {
    $source = EnergySource::create([
        'name' => 'Réseau', 'type' => 'reseau', 'maintenance_interval_hours' => 0,
        'status' => 'operationnel', 'is_active' => true,
    ]);
    $payload = ['uuid' => (string) Str::uuid(), 'energy_source_id' => $source->id,
        'reading_date' => now()->toDateString(), 'hours_run' => 8, 'kwh_produced' => 40];

    $this->postJson('/api/v1/sync/push', ['operations' => [
        ['op_uuid' => (string) Str::uuid(), 'type' => 'energy_reading.create', 'payload' => $payload],
    ]])->assertOk();
    $this->postJson('/api/v1/sync/push', ['operations' => [
        ['op_uuid' => (string) Str::uuid(), 'type' => 'energy_reading.create', 'payload' => $payload],
    ]])->assertOk();

    // Un seul relevé par (source, jour) — pas de doublon en base.
    expect(EnergyReading::where('energy_source_id', $source->id)->count())->toBe(1);
});

test('relevé eau : coût estimé au prix du m³ et niveau citerne rafraîchi', function () {
    \App\Models\Setting::set('energie.water_price_m3', '2000');
    $source = WaterSource::create([
        'name' => 'Citerne A', 'type' => 'citerne', 'capacity_liters' => 10000,
        'current_level_liters' => 8000, 'status' => 'operationnel', 'is_active' => true,
    ]);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'water_reading.create',
        'payload' => [
            'uuid' => (string) Str::uuid(), 'water_source_id' => $source->id,
            'reading_date' => now()->toDateString(),
            'volume_consumed_liters' => 1500, 'quality_ph' => 7.2,
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $reading = WaterReading::where('is_refill', false)->first();
    // 1,5 m³ × 2 000 = 3 000 GNF
    expect((float) $reading->cost)->toEqualWithDelta(3000, 0.01)
        ->and((float) $reading->quality_ph)->toEqualWithDelta(7.2, 0.01)
        ->and($reading->is_refill)->toBeFalsy();
});

test('le pull descend les sources d’énergie ACTIVES', function () {
    EnergySource::create([
        'name' => 'Groupe actif', 'type' => 'groupe', 'maintenance_interval_hours' => 250,
        'status' => 'operationnel', 'is_active' => true,
    ]);
    EnergySource::create([
        'name' => 'Groupe retiré', 'type' => 'groupe', 'maintenance_interval_hours' => 250,
        'status' => 'hors_service', 'is_active' => false,
    ]);

    $names = collect($this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.energy_sources.upserts'))
        ->pluck('name');

    expect($names)->toContain('Groupe actif')
        ->and($names)->not->toContain('Groupe retiré');
});
