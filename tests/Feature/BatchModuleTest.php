<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Contexte ferme (trait BelongsToFarm) pour la cohérence farm_id.
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    // Permissions portées par la colonne JSON roles.permissions ; les Gates
    // retombent sur le NOM de rôle (admin/manager/operator/viewer) quand aucune
    // matrice module_permissions n'existe — d'où ces noms exacts.
    $makeRole = fn (string $name, array $perms) => Role::firstOrCreate(
        ['name' => $name],
        ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
    );

    $admin    = $makeRole('admin',    ['L', 'C', 'M', 'S']);
    $manager  = $makeRole('manager',  ['L', 'C', 'M']);
    $operator = $makeRole('operator', ['L', 'C']);
    $readonly = $makeRole('viewer',   ['L']);

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
    $this->managerUser = User::factory()->create(['role_id' => $manager->id]);
    $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
    $this->readonlyUser = User::factory()->create(['role_id' => $readonly->id]);

    $this->building = Building::factory()->create(['type' => 'chair']);
    $this->employee = Employee::factory()->create();
    $this->provider = Provider::factory()->create();
});

// ── PERMISSIONS ──

test('un visiteur (L) peut voir la liste des lots', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('batches.index'))
        ->assertOk();
});

test('un visiteur (L) ne peut PAS créer un lot', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('batches.create'))
        ->assertRedirect();
});

test('un opérateur (C) peut accéder au formulaire de création', function () {
    $this->actingAs($this->operatorUser)
        ->get(route('batches.create'))
        ->assertOk();
});

test('un visiteur ne peut PAS supprimer un lot', function () {
    $batch = Batch::factory()->create(['building_id' => $this->building->id]);
    $this->actingAs($this->readonlyUser)
        ->delete(route('batches.destroy', $batch))
        ->assertRedirect();
});

test('un manager peut voir le détail d\'un lot', function () {
    $batch = Batch::factory()->create(['building_id' => $this->building->id]);
    $this->actingAs($this->managerUser)
        ->get(route('batches.show', $batch))
        ->assertOk();
});

test('un manager peut accéder au formulaire d\'édition', function () {
    $batch = Batch::factory()->create(['building_id' => $this->building->id]);
    $this->actingAs($this->managerUser)
        ->get(route('batches.edit', $batch))
        ->assertOk();
});

// ── CLÔTURE ──

test('clôturer un lot change le statut en Terminé', function () {
    $batch = Batch::factory()->create([
        'building_id'      => $this->building->id,
        'status'           => 'Actif',
        'current_quantity' => 500,
        'initial_quantity' => 1000,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('batches.close', $batch), [
            'actual_sell_price_per_unit' => 15000,
            'closing_date'              => now()->toDateString(),
            'observations'              => 'Clôture test',
        ]);

    $batch->refresh();
    expect($batch->status)->toBe('Terminé');
    expect($batch->closing_date)->not->toBeNull();
});

// ── TRANSFERT ──

test('transférer un lot change le building_id', function () {
    // Créer un protocole (requis par TransferBatchRequest)
    $protocol = Protocol::create([
        'name' => 'Proto Chair Standard',
        'type' => 'chair',
    ]);

    $building2 = Building::factory()->create(['type' => 'chair']);

    $batch = Batch::factory()->create([
        'building_id'        => $this->building->id,
        'status'             => 'Actif',
        'current_quantity'   => 500,
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $building2->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'chair',
            'transfer_date'      => now()->toDateString(),
            'notes'              => 'Test transfert',
        ]);

    $batch->refresh();
    expect($batch->building_id)->toBe($building2->id);
});