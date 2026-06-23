<?php

use App\Models\AssetMaintenanceLog;
use App\Models\EnergySource;
use App\Models\TaskAssignment;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── Actif & amortissement ───────────────────────────────────────────────────

test('les champs actif sont sauvegardés sur une source d\'énergie', function () {
    $source = EnergySource::create([
        'farm_id'           => $this->farm->id,
        'name'              => 'Groupe Perkins 100kVA',
        'type'              => 'groupe',
        'fuel_type'         => 'gasoil',
        'is_active'         => true,
        'serial_number'     => 'SN-12345',
        'purchase_date'     => '2022-01-15',
        'purchase_price'    => 180_000_000,
        'depreciation_years' => 10,
        'warranty_expiry'   => '2027-01-15',
        'service_contract_ref' => 'CTR-2024-001',
    ]);

    expect($source->serial_number)->toBe('SN-12345');
    expect($source->purchase_price)->toBe('180000000');
    expect($source->depreciation_years)->toBe(10);
    expect($source->warranty_expiry->format('Y-m-d'))->toBe('2027-01-15');
    expect($source->service_contract_ref)->toBe('CTR-2024-001');
});

test('la valeur résiduelle est calculée correctement (amortissement linéaire)', function () {
    $source = EnergySource::create([
        'farm_id'            => $this->farm->id,
        'name'               => 'Groupe Test',
        'type'               => 'groupe',
        'is_active'          => true,
        'purchase_date'      => now()->subYears(5)->toDateString(),
        'purchase_price'     => 100_000_000,
        'depreciation_years' => 10,
    ]);

    // Après 5 ans sur 10 → ~50% de la valeur initiale
    $residual = $source->residual_value;
    expect($residual)->not->toBeNull();
    expect($residual)->toBeGreaterThan(40_000_000);
    expect($residual)->toBeLessThan(60_000_000);
});

test('la valeur résiduelle est null si le prix d\'achat n\'est pas renseigné', function () {
    $source = EnergySource::create([
        'farm_id'   => $this->farm->id,
        'name'      => 'EDG Réseau',
        'type'      => 'edg',
        'is_active' => true,
    ]);

    expect($source->residual_value)->toBeNull();
});

test('le statut de garantie est correct', function () {
    $active = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'A', 'type' => 'groupe', 'is_active' => true,
        'warranty_expiry' => now()->addYears(1)->toDateString(),
    ]);

    $expiresSoon = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'B', 'type' => 'groupe', 'is_active' => true,
        'warranty_expiry' => now()->addDays(15)->toDateString(),
    ]);

    $expired = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'C', 'type' => 'groupe', 'is_active' => true,
        'warranty_expiry' => now()->subYear()->toDateString(),
    ]);

    $unknown = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'D', 'type' => 'groupe', 'is_active' => true,
    ]);

    expect($active->warranty_status)->toBe('active');
    expect($expiresSoon->warranty_status)->toBe('expires_soon');
    expect($expired->warranty_status)->toBe('expired');
    expect($unknown->warranty_status)->toBe('unknown');
});

// ─── Journal de maintenance ───────────────────────────────────────────────────

test('recordMaintenance crée un AssetMaintenanceLog', function () {
    $source = EnergySource::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Groupe Perkins',
        'type'                       => 'groupe',
        'is_active'                  => true,
        'total_hours_run'            => 300,
        'maintenance_interval_hours' => 250,
        'status'                     => 'maintenance',
    ]);

    $this->actingAs($this->managerUser);

    $response = $this->put(route('utilities.energy.maintenance', $source), [
        'maintenance_type' => 'vidange',
        'description'      => 'Vidange huile + filtre à huile',
        'cost'             => 450_000,
        'technician'       => 'Technicien Conakry SARL',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $log = AssetMaintenanceLog::where('energy_source_id', $source->id)->first();
    expect($log)->not->toBeNull();
    expect($log->type)->toBe('vidange');
    expect($log->technician)->toBe('Technicien Conakry SARL');
    expect((float) $log->cost)->toBe(450_000.0);
    expect((float) $log->hours_at_maintenance)->toBe(300.0);

    $source->refresh();
    expect($source->status)->toBe('operationnel');
    expect($source->last_maintenance_at)->not->toBeNull();
});

test('recordMaintenance auto-complète la tâche maintenance_preventive du jour', function () {
    $source = EnergySource::create([
        'farm_id'   => $this->farm->id,
        'name'      => 'Groupe Cat',
        'type'      => 'groupe',
        'is_active' => true,
        'status'    => 'maintenance',
    ]);

    $task = TaskAssignment::create([
        'farm_id'          => $this->farm->id,
        'category'         => 'maintenance_preventive',
        'title'            => "Maintenance requise : Groupe Cat",
        'scheduled_date'   => now()->toDateString(),
        'status'           => 'a_faire',
        'is_auto_generated' => true,
    ]);

    $this->actingAs($this->managerUser);

    $this->put(route('utilities.energy.maintenance', $source), [
        'maintenance_type' => 'inspection',
    ]);

    expect($task->fresh()->status)->toBe('fait');
    expect($task->fresh()->completed_at)->not->toBeNull();

    $log = AssetMaintenanceLog::where('energy_source_id', $source->id)->first();
    expect($log->task_assignment_id)->toBe($task->id);
});

test('le coût cumulé de maintenance est calculé', function () {
    $source = EnergySource::create([
        'farm_id' => $this->farm->id, 'name' => 'Groupe X', 'type' => 'groupe', 'is_active' => true,
    ]);

    AssetMaintenanceLog::create([
        'farm_id' => $this->farm->id, 'energy_source_id' => $source->id,
        'user_id' => $this->managerUser->id,
        'maintenance_date' => now()->subMonths(3)->toDateString(), 'type' => 'vidange', 'cost' => 300_000,
    ]);

    AssetMaintenanceLog::create([
        'farm_id' => $this->farm->id, 'energy_source_id' => $source->id,
        'user_id' => $this->managerUser->id,
        'maintenance_date' => now()->subMonths(1)->toDateString(), 'type' => 'filtres', 'cost' => 150_000,
    ]);

    expect($source->cumulative_maintenance_cost)->toBe(450_000.0);
});

// ─── Commande maintenance:check ──────────────────────────────────────────────

test('maintenance:check génère une tâche pour un groupe dont la maintenance est due', function () {
    EnergySource::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Groupe Urgent',
        'type'                       => 'groupe',
        'is_active'                  => true,
        'total_hours_run'            => 300,
        'maintenance_interval_hours' => 250,
        'last_maintenance_at'        => null,
    ]);

    $this->artisan('maintenance:check')->assertSuccessful();

    $task = TaskAssignment::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('category', 'maintenance_preventive')
        ->whereDate('scheduled_date', now()->toDateString())
        ->where('title', 'like', '%Groupe Urgent%')
        ->first();

    expect($task)->not->toBeNull();
    expect($task->status)->toBe('a_faire');
    expect($task->is_auto_generated)->toBeTrue();
});

test('maintenance:check ne crée pas de doublon si la tâche existe déjà', function () {
    $source = EnergySource::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Groupe Double',
        'type'                       => 'groupe',
        'is_active'                  => true,
        'total_hours_run'            => 300,
        'maintenance_interval_hours' => 250,
    ]);

    TaskAssignment::create([
        'farm_id'          => $this->farm->id,
        'category'         => 'maintenance_preventive',
        'title'            => "Maintenance requise : Groupe Double",
        'scheduled_date'   => now()->toDateString(),
        'status'           => 'a_faire',
        'is_auto_generated' => true,
    ]);

    $this->artisan('maintenance:check')->assertSuccessful();

    $count = TaskAssignment::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('category', 'maintenance_preventive')
        ->whereDate('scheduled_date', now()->toDateString())
        ->where('title', 'like', '%Groupe Double%')
        ->count();

    expect($count)->toBe(1);
});

test('maintenance:check ignore les groupes dont la maintenance n\'est pas due', function () {
    EnergySource::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Groupe Neuf',
        'type'                       => 'groupe',
        'is_active'                  => true,
        'total_hours_run'            => 50,
        'maintenance_interval_hours' => 250,
        'next_maintenance_at'        => now()->addDays(30)->toDateTimeString(),
    ]);

    $this->artisan('maintenance:check')->assertSuccessful();

    $task = TaskAssignment::withoutGlobalScopes()
        ->where('farm_id', $this->farm->id)
        ->where('category', 'maintenance_preventive')
        ->whereDate('scheduled_date', now()->toDateString())
        ->where('title', 'like', '%Groupe Neuf%')
        ->first();

    expect($task)->toBeNull();
});
