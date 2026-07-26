<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Employee;
use App\Models\Formula;
use App\Models\Harvest;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\Plot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * M4 — Provenderie : le meunier LANCE un OP sur place (il ne pouvait que
 * clôturer). Cultures : DÉLAI AVANT RÉCOLTE (DAR) après un traitement phyto —
 * pendant exact du délai d'attente en élevage, levée automatique à l'échéance.
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

function millSetup($test): array
{
    // Factories : évite de deviner les colonnes NOT NULL du référentiel RH/industriel.
    $formula  = Formula::factory()->create(['name' => 'Démarrage chair']);
    $machine  = MillMachine::factory()->create(['name' => 'Broyeur A', 'capacity_per_hour' => 500, 'status' => 'Actif']);
    $employee = Employee::factory()->create([
        'employee_id' => 'EMP-M4', 'first_name' => 'Moussa', 'last_name' => 'Touré',
        'job_title' => 'Meunier', 'status' => 'Actif',
    ]);

    return [$formula, $machine, $employee];
}

function millOp(int $formulaId, array $machineIds, int $supervisorId, int $bags = 10): array
{
    return ['op_uuid' => (string) Str::uuid(), 'type' => 'mill_production.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'formula_id' => $formulaId,
            'machine_ids' => $machineIds,
            'nb_bags' => $bags,
            'supervisor_id' => $supervisorId,
        ]];
}

test('le meunier lance un OP depuis le mobile (statut Planifié, machines attachées)', function () {
    [$formula, $machine, $employee] = millSetup($this);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        millOp($formula->id, [$machine->id], $employee->id, 10),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success');

    $op = MillProduction::first();
    expect($op->status)->toBe('Planifié')
        // 10 sacs × 50 kg = 500 kg planifiés (UnitConverter).
        ->and((float) $op->quantity_produced)->toEqualWithDelta(500, 0.01)
        ->and($op->supervisor_id)->toBe($employee->id)
        ->and($op->machines()->count())->toBe(1)
        // Capacité FIGÉE au lancement (snapshot pivot).
        ->and((float) $op->machines()->first()->pivot->snapshot_capacity_per_hour)->toEqualWithDelta(500, 0.01);
});

test('idempotence : le rejeu du lancement ne crée pas un second OP', function () {
    [$formula, $machine, $employee] = millSetup($this);
    $op = millOp($formula->id, [$machine->id], $employee->id);

    $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk();
    $replay = $this->postJson('/api/v1/sync/push', ['operations' => [$op]])->assertOk()->json('results.0');

    expect($replay['status'])->toBe('already_synced')
        ->and(MillProduction::count())->toBe(1);
});

test('machine déjà engagée sur un OP ouvert → conflict', function () {
    [$formula, $machine, $employee] = millSetup($this);

    $this->postJson('/api/v1/sync/push', ['operations' => [
        millOp($formula->id, [$machine->id], $employee->id),
    ]])->assertOk();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [
        millOp($formula->id, [$machine->id], $employee->id),
    ]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('conflict')
        ->and(MillProduction::count())->toBe(1);
});

test('le pull descend les machines du moulin et les employés ACTIFS', function () {
    [$formula, $machine, $employee] = millSetup($this);
    Employee::factory()->create(['employee_id' => 'EMP-OUT', 'status' => 'Sorti']);

    $entities = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities');

    expect(collect($entities['mill_machines']['upserts'])->pluck('name'))->toContain('Broyeur A');

    $names = collect($entities['employees']['upserts'])->pluck('employee_id');
    expect($names)->toContain('EMP-M4')
        ->and($names)->not->toContain('EMP-OUT');

    // Aucune donnée sensible RH ne descend au terrain.
    $first = $entities['employees']['upserts'][0];
    expect($first)->not->toHaveKey('salary')
        ->and($first)->not->toHaveKey('contract_type');
});

// ─── Cultures : délai avant récolte ───

function cropCycleWithPhyto($test, int $preharvestDays, int $daysAgo = 0): CropCycle
{
    $plot = Plot::create(['name' => 'Parcelle M4', 'area_ha' => 1.5, 'status' => 'disponible']);
    $cycle = CropCycle::create([
        'plot_id' => $plot->id, 'code' => 'CY-M4-' . Str::random(4), 'crop_name' => 'Tomate',
        'planting_date' => now()->subDays(60)->toDateString(), 'status' => 'en_cours',
        'area_used_ha' => 1.5,
    ]);
    CropInput::create([
        'crop_cycle_id' => $cycle->id, 'type' => 'phyto', 'name' => 'Insecticide XZ',
        'input_date' => now()->subDays($daysAgo)->toDateString(),
        'preharvest_days' => $preharvestDays, 'quantity' => 2, 'unit' => 'L',
    ]);

    return $cycle->fresh();
}

test('la récolte est REFUSÉE tant que le délai avant récolte court (sync)', function () {
    $cycle = cropCycleWithPhyto($this, 14, 2); // traité il y a 2 j, DAR 14 j

    expect($cycle->isHarvestBlocked())->toBeTrue();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'harvest.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'crop_cycle_id' => $cycle->id,
            'harvest_date' => now()->toDateString(),
            'quantity' => 120,
            'unit' => 'kg',
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('conflict')
        ->and(Harvest::count())->toBe(0);
});

test('le délai avant récolte purgé libère la récolte (levée automatique)', function () {
    $cycle = cropCycleWithPhyto($this, 7, 10); // traité il y a 10 j, DAR 7 j

    expect($cycle->isHarvestBlocked())->toBeFalse();

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) Str::uuid(), 'type' => 'harvest.create',
        'payload' => [
            'uuid' => (string) Str::uuid(),
            'crop_cycle_id' => $cycle->id,
            'harvest_date' => now()->toDateString(),
            'quantity' => 120,
            'unit' => 'kg',
        ],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('success')
        ->and(Harvest::count())->toBe(1);
});

test('un intrant sans DAR (engrais) ne bloque pas la récolte', function () {
    $plot = Plot::create(['name' => 'Parcelle libre', 'area_ha' => 1, 'status' => 'disponible']);
    $cycle = CropCycle::create([
        'plot_id' => $plot->id, 'code' => 'CY-FREE', 'crop_name' => 'Maïs',
        'planting_date' => now()->subDays(80)->toDateString(), 'status' => 'en_cours', 'area_used_ha' => 1,
    ]);
    CropInput::create([
        'crop_cycle_id' => $cycle->id, 'type' => 'engrais', 'name' => 'NPK',
        'input_date' => now()->toDateString(), 'preharvest_days' => null, 'quantity' => 50, 'unit' => 'kg',
    ]);

    expect($cycle->fresh()->isHarvestBlocked())->toBeFalse();
});

test("l'intrant le plus contraignant fixe l'échéance de récolte", function () {
    $cycle = cropCycleWithPhyto($this, 5, 0);
    CropInput::create([
        'crop_cycle_id' => $cycle->id, 'type' => 'phyto', 'name' => 'Fongicide long',
        'input_date' => now()->toDateString(), 'preharvest_days' => 21, 'quantity' => 1, 'unit' => 'L',
    ]);

    $blocking = $cycle->fresh()->activePreharvestInterval();
    expect($blocking->name)->toBe('Fongicide long')
        ->and($blocking->preharvest_days_left)->toBe(21);
});

test('la garde de récolte vaut aussi côté web (RecordHarvest, point unique)', function () {
    $cycle = cropCycleWithPhyto($this, 14, 1);

    expect(fn () => app(\App\Actions\Crop\RecordHarvest::class)->execute($cycle, [
        'harvest_date' => now()->toDateString(), 'quantity' => 50, 'unit' => 'kg',
    ]))->toThrow(Exception::class, 'délai avant récolte');
});
