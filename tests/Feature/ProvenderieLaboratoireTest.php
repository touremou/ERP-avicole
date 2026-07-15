<?php

use App\Models\Formula;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\RawMaterial;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── Formulation : validation des ingrédients ────────────────────────────────

test('créer une formule sans renseigner tous les pourcentages ne génère pas d\'erreur de validation', function () {
    $m1 = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Maïs', 'unit' => 'kg', 'stock_qty' => 100, 'unit_cost' => 500, 'is_active' => true]);
    $m2 = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Soja', 'unit' => 'kg', 'stock_qty' => 100, 'unit_cost' => 800, 'is_active' => true]);
    $m3 = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Son', 'unit' => 'kg', 'stock_qty' => 100, 'unit_cost' => 300, 'is_active' => true]);

    // Seuls m1 et m2 sont renseignés ; m3 a un pourcentage vide (comme dans le form)
    $response = $this->actingAs($this->adminUser)->post(route('formulas.store'), [
        'name'               => 'Test Mix',
        'code'               => 'TM-01',
        'target_type'        => 'ponte',
        'total_batch_weight' => 1000,
        'ingredients'        => [
            ['id' => $m1->id, 'percentage' => '60'],
            ['id' => $m2->id, 'percentage' => '40'],
            ['id' => $m3->id, 'percentage' => ''],   // vide → ignoré
        ],
    ]);

    // Doit créer la formule (redirection sans erreur de validation percentage)
    $response->assertSessionHasNoErrors();
    expect(Formula::where('code', 'TM-01')->exists())->toBeTrue();
});

test('la somme des pourcentages non nuls doit être 100', function () {
    $m1 = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Maïs', 'unit' => 'kg', 'stock_qty' => 100, 'unit_cost' => 500, 'is_active' => true]);
    $m2 = RawMaterial::create(['farm_id' => $this->farm->id, 'name' => 'Soja', 'unit' => 'kg', 'stock_qty' => 100, 'unit_cost' => 800, 'is_active' => true]);

    $response = $this->actingAs($this->adminUser)->post(route('formulas.store'), [
        'name'               => 'Bad Mix',
        'code'               => 'BAD-01',
        'target_type'        => 'ponte',
        'total_batch_weight' => 1000,
        'ingredients'        => [
            ['id' => $m1->id, 'percentage' => '50'],
            ['id' => $m2->id, 'percentage' => '30'],  // 80% total → rejeté
        ],
    ]);

    $response->assertSessionHasErrors('ingredients');
    expect(Formula::where('code', 'BAD-01')->exists())->toBeFalse();
});

// ─── Mill machines : ENUM Désactivé ─────────────────────────────────────────

test('changer le statut d\'une machine en Désactivé est accepté', function () {
    $machine = MillMachine::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Broyeur A',
        'type'                       => 'Broyeur',
        'capacity_per_hour'          => 500,
        'maintenance_interval_hours' => 500,
        'status'                     => 'Opérationnel',
    ]);

    $this->actingAs($this->adminUser)->put(route('machines.status', $machine->id), [
        'status' => 'Désactivé',
    ])->assertRedirect();

    expect($machine->fresh()->status)->toBe('Désactivé');
});

// ─── Production : vérification de disponibilité machine ──────────────────────

test('planifier un OP sur une machine portant un OP ouvert (Planifié) est bloqué', function () {
    $machine = MillMachine::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Mélangeur B',
        'type'                       => 'Mélangeur',
        'capacity_per_hour'          => 1000,
        'maintenance_interval_hours' => 500,
        'status'                     => 'Opérationnel',
    ]);

    $formula = Formula::create([
        'farm_id'            => $this->farm->id,
        'name'               => 'Formule Ponte',
        'code'               => 'FP-01',
        'target_type'        => 'ponte',
        'total_batch_weight' => 1000,
        'is_active'          => true,
    ]);

    // Premier OP ouvert sur la machine. Statut réel à la création = 'Planifié'
    // (le système n'a pas d'état 'En cours' persisté) : c'est justement ce cas
    // que l'ancienne version laissait passer.
    $existing = MillProduction::create([
        'farm_id'           => $this->farm->id,
        'batch_number'      => 'OP-TEST-001',
        'formula_id'        => $formula->id,
        'quantity_produced' => 500,
        'operator_id'       => $this->operatorUser->id,
        'status'            => 'Planifié',
    ]);
    $existing->machines()->attach([$machine->id => ['snapshot_capacity_per_hour' => 1000]]);

    // Tenter un second OP sur la même machine
    $employee = \App\Models\Employee::create([
        'farm_id'    => $this->farm->id,
        'first_name' => 'Jean',
        'last_name'  => 'Dupont',
        'phone'      => '620000001',
        'job_title'     => 'Opérateur',
        'department'    => 'Production',
        'contract_type' => 'CDI',
        'hire_date'     => '2024-01-01',
        'status'        => 'Actif',
    ]);

    $response = $this->actingAs($this->adminUser)->post(route('production.store'), [
        'formula_id'    => $formula->id,
        'machine_ids'   => [$machine->id],
        'nb_bags'       => 10,
        'supervisor_id' => $employee->id,
    ]);

    $response->assertSessionHas('error');
    expect(MillProduction::where('batch_number', '!=', 'OP-TEST-001')->count())->toBe(0);
});

test('planifier un OP sur une machine disponible réussit', function () {
    $machine = MillMachine::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Ensacheuse C',
        'type'                       => 'Ensacheuse',
        'capacity_per_hour'          => 800,
        'maintenance_interval_hours' => 500,
        'status'                     => 'Opérationnel',
    ]);

    $formula = Formula::create([
        'farm_id'            => $this->farm->id,
        'name'               => 'Formule Chair',
        'code'               => 'FC-01',
        'target_type'        => 'chair',
        'total_batch_weight' => 1000,
        'is_active'          => true,
    ]);

    $employee = \App\Models\Employee::create([
        'farm_id'    => $this->farm->id,
        'first_name' => 'Ali',
        'last_name'  => 'Traoré',
        'phone'      => '620000002',
        'job_title'     => 'Opérateur',
        'department'    => 'Production',
        'contract_type' => 'CDI',
        'hire_date'     => '2024-01-01',
        'status'        => 'Actif',
    ]);

    $response = $this->actingAs($this->adminUser)->post(route('production.store'), [
        'formula_id'    => $formula->id,
        'machine_ids'   => [$machine->id],
        'nb_bags'       => 5,
        'supervisor_id' => $employee->id,
    ]);

    $response->assertSessionHasNoErrors();
    expect(MillProduction::where('status', 'Planifié')->count())->toBe(1);
});

test('un OP clôturé (Terminé) libère la machine pour un nouvel ordre', function () {
    $machine = MillMachine::create([
        'farm_id'                    => $this->farm->id,
        'name'                       => 'Broyeur D',
        'type'                       => 'Broyeur',
        'capacity_per_hour'          => 600,
        'maintenance_interval_hours' => 500,
        'status'                     => 'Opérationnel',
    ]);

    $formula = Formula::create([
        'farm_id'            => $this->farm->id,
        'name'               => 'Formule Ponte',
        'code'               => 'FP-02',
        'target_type'        => 'ponte',
        'total_batch_weight' => 1000,
        'is_active'          => true,
    ]);

    // Ancien OP déjà clôturé sur cette machine → ne doit PAS bloquer.
    $done = MillProduction::create([
        'farm_id'           => $this->farm->id,
        'batch_number'      => 'OP-DONE-001',
        'formula_id'        => $formula->id,
        'quantity_produced' => 500,
        'operator_id'       => $this->operatorUser->id,
        'status'            => 'Terminé',
    ]);
    $done->machines()->attach([$machine->id => ['snapshot_capacity_per_hour' => 600]]);

    $employee = \App\Models\Employee::create([
        'farm_id'    => $this->farm->id,
        'first_name' => 'Mamadou',
        'last_name'  => 'Diallo',
        'phone'      => '620000003',
        'job_title'     => 'Opérateur',
        'department'    => 'Production',
        'contract_type' => 'CDI',
        'hire_date'     => '2024-01-01',
        'status'        => 'Actif',
    ]);

    $response = $this->actingAs($this->adminUser)->post(route('production.store'), [
        'formula_id'    => $formula->id,
        'machine_ids'   => [$machine->id],
        'nb_bags'       => 8,
        'supervisor_id' => $employee->id,
    ]);

    $response->assertSessionHasNoErrors();
    expect(MillProduction::where('status', 'Planifié')->count())->toBe(1);
});
