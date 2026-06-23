<?php

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Permission;
use App\Models\ProductionType;
use App\Models\Protocol;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Contexte ferme (trait BelongsToFarm) pour la cohérence farm_id.
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    // La matrice `module_permissions` (Modules × Rôles) est la SEULE source
    // de vérité des Gates (cf. AppServiceProvider) : on dérive ici une ligne
    // par module à partir de la matrice LCMS (L/C/M/S) de chaque rôle.
    $makeRole = function (string $name, array $perms) {
        $role = Role::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]
        );

        $now = now();
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                [
                    'can_read'   => in_array('L', $perms, true),
                    'can_create' => in_array('C', $perms, true),
                    'can_modify' => in_array('M', $perms, true),
                    'can_delete' => in_array('S', $perms, true),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $role;
    };

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

test('la mutation d\'une poussinière vers la phase ponte bascule le type de production (graduation)', function () {
    $protocol = Protocol::create([
        'name' => 'Proto Ponte Standard',
        'type' => 'ponte',
    ]);

    $poussiniereBuilding = Building::factory()->create(['type' => 'poussiniere']);
    $ponteBuilding       = Building::factory()->create(['type' => 'ponte']);

    $poussiniereType = ProductionType::resolveOrCreate('poussiniere', null);

    $batch = Batch::factory()->create([
        'building_id'        => $poussiniereBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 500,
        'production_type_id' => $poussiniereType->id,
    ]);

    expect($batch->type)->toBe('poussiniere');

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $ponteBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'ponte',
            'transfer_date'      => now()->toDateString(),
            'notes'              => 'Démarrage de la ponte après la poussinière',
        ])
        ->assertSessionDoesntHaveErrors();

    $batch->refresh();

    // Le lot a basculé vers le type de production "ponte" (même espèce) :
    // production_type_id est la source de vérité (feedSector, tracksEggs,
    // calculateExpectedEndDate...), pas seulement production_phase.
    expect($batch->type)->toBe('ponte');
    expect($batch->production_phase)->toBe('ponte');
    expect($batch->building_id)->toBe($ponteBuilding->id);

    $history = $batch->transfer_history;
    $lastTransfer = end($history);
    expect($lastTransfer['old_type'])->toBe('poussiniere');
    expect($lastTransfer['new_type'])->toBe('ponte');
});

test('la mutation applique la souche saisie (poussinière Non spécifié → ponte)', function () {
    $protocol = Protocol::create(['name' => 'Proto Ponte Souche', 'type' => 'ponte']);

    $poussiniereBuilding = Building::factory()->create(['type' => 'poussiniere']);
    $ponteBuilding       = Building::factory()->create(['type' => 'ponte']);

    $poussiniereType = ProductionType::resolveOrCreate('poussiniere', null);

    $batch = Batch::factory()->create([
        'building_id'        => $poussiniereBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 500,
        'production_type_id' => $poussiniereType->id,
        'model_name'         => 'Non spécifié',
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $ponteBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'ponte',
            'transfer_date'      => now()->toDateString(),
            'model_name'         => 'ISA Brown',
        ])
        ->assertSessionDoesntHaveErrors();

    // La souche renseignée lors de la graduation est appliquée : les normes
    // (BatchAdvisorService) et protocoles s'appliquent sans ré-édition.
    expect($batch->fresh()->model_name)->toBe('ISA Brown');
});

test('la mutation sans souche ne réécrase pas une souche connue', function () {
    $protocol = Protocol::create(['name' => 'Proto Ponte Garde', 'type' => 'ponte']);

    $poussiniereBuilding = Building::factory()->create(['type' => 'poussiniere']);
    $ponteBuilding       = Building::factory()->create(['type' => 'ponte']);

    $poussiniereType = ProductionType::resolveOrCreate('poussiniere', null);

    $batch = Batch::factory()->create([
        'building_id'        => $poussiniereBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 500,
        'production_type_id' => $poussiniereType->id,
        'model_name'         => 'Lohmann Brown',
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $ponteBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'ponte',
            'transfer_date'      => now()->toDateString(),
            'model_name'         => '', // champ laissé vide → on garde l'existant
        ])
        ->assertSessionDoesntHaveErrors();

    expect($batch->fresh()->model_name)->toBe('Lohmann Brown');
});

test('la mutation refuse un bâtiment incompatible avec la nouvelle phase', function () {
    $protocol = Protocol::create([
        'name' => 'Proto Ponte Standard 2',
        'type' => 'ponte',
    ]);

    $poussiniereBuilding = Building::factory()->create(['type' => 'poussiniere']);
    $chairBuilding       = Building::factory()->create(['type' => 'chair']);

    $poussiniereType = ProductionType::resolveOrCreate('poussiniere', null);

    $batch = Batch::factory()->create([
        'building_id'        => $poussiniereBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 500,
        'production_type_id' => $poussiniereType->id,
    ]);

    // On vise la phase "ponte" mais on choisit un bâtiment de type "chair" :
    // incompatible avec le type CIBLE de la mutation.
    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $chairBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'ponte',
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionHasErrors('target_building_id');

    $batch->refresh();
    expect($batch->building_id)->toBe($poussiniereBuilding->id);
    expect($batch->type)->toBe('poussiniere');
});

test('une poussinière peut graduer vers la chair (phase demandée par le métier)', function () {
    $protocol = Protocol::create(['name' => 'Proto Chair', 'type' => 'chair']);

    $poussiniereBuilding = Building::factory()->create(['type' => 'poussiniere']);
    $chairBuilding       = Building::factory()->create(['type' => 'chair']);

    $batch = Batch::factory()->create([
        'building_id'        => $poussiniereBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 800,
        'production_type_id' => ProductionType::resolveOrCreate('poussiniere', null)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $chairBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'chair',
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionDoesntHaveErrors();

    $batch->refresh();
    expect($batch->type)->toBe('chair');
    expect($batch->building_id)->toBe($chairBuilding->id);
});

test('une transformation sur place (même bâtiment mixte) gradue le lot sans le déplacer', function () {
    $protocol = Protocol::create(['name' => 'Proto Ponte Sur Place', 'type' => 'ponte']);

    // Bâtiment mixte : accueille la poussinière puis la ponte sur place.
    $mixteBuilding = Building::factory()->create(['type' => 'mixte']);

    $batch = Batch::factory()->create([
        'building_id'        => $mixteBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 600,
        'production_type_id' => ProductionType::resolveOrCreate('poussiniere', null)->id,
    ]);

    // On garde le MÊME bâtiment : la transformation sur place doit être permise
    // dès lors que la phase change (graduation).
    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $mixteBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'ponte',
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionDoesntHaveErrors();

    $batch->refresh();
    expect($batch->type)->toBe('ponte');
    expect($batch->building_id)->toBe($mixteBuilding->id);
    // Le bâtiment courant reste Occupé (pas de vide sanitaire sur place).
    expect($mixteBuilding->fresh()->status)->toBe(\App\Models\Building::STATUS_OCCUPE);
});

test('rester dans le même bâtiment sans changer de phase est refusé', function () {
    $protocol = Protocol::create(['name' => 'Proto No-op', 'type' => 'chair']);
    $chairBuilding = Building::factory()->create(['type' => 'chair']);

    $batch = Batch::factory()->create([
        'building_id'        => $chairBuilding->id,
        'status'             => 'Actif',
        'current_quantity'   => 400,
        'production_type_id' => ProductionType::resolveOrCreate('chair', null)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $chairBuilding->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'chair', // identique → aucun effet
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionHasErrors('target_building_id');
});

// ── COMPATIBILITÉ BÂTIMENT / ESPÈCE (NON-VOLAILLE) ──

test('une chèvre peut graduer de l\'engraissement vers la laitière entre deux chèvreries', function () {
    $chevre = \App\Models\Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $protocol = Protocol::create(['name' => 'Proto Caprin Laitière', 'type' => 'laitiere']);

    $chevrerie1 = Building::factory()->create(['type' => 'chevrerie']);
    $chevrerie2 = Building::factory()->create(['type' => 'chevrerie']);

    $batch = Batch::factory()->create([
        'species_id'         => $chevre->id,
        'building_id'        => $chevrerie1->id,
        'status'             => 'Actif',
        'current_quantity'   => 30,
        'production_type_id' => ProductionType::resolveOrCreate('engraissement', $chevre->id)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $chevrerie2->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'laitiere',
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionDoesntHaveErrors();

    $batch->refresh();
    expect($batch->type)->toBe('laitiere');
    expect($batch->building_id)->toBe($chevrerie2->id);
});

test('une chèvre ne peut pas être transférée dans une bergerie', function () {
    $chevre = \App\Models\Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $protocol = Protocol::create(['name' => 'Proto Caprin Engraissement', 'type' => 'engraissement']);

    $chevrerie = Building::factory()->create(['type' => 'chevrerie']);
    $bergerie  = Building::factory()->create(['type' => 'bergerie']);

    $batch = Batch::factory()->create([
        'species_id'         => $chevre->id,
        'building_id'        => $chevrerie->id,
        'status'             => 'Actif',
        'current_quantity'   => 20,
        'production_type_id' => ProductionType::resolveOrCreate('engraissement', $chevre->id)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $bergerie->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'engraissement',
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionHasErrors('target_building_id');

    $batch->refresh();
    expect($batch->building_id)->toBe($chevrerie->id);
});

test('la création d\'un lot caprin dans un bâtiment volaille est refusée', function () {
    $chevre = \App\Models\Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $chairBuilding = Building::factory()->create(['type' => 'chair']);

    $this->actingAs($this->managerUser)
        ->post(route('batches.store'), [
            'code'               => 'CAP-001',
            'building_id'        => $chairBuilding->id,
            'type'               => 'engraissement',
            'species_id'         => $chevre->id,
            'employee_id'        => $this->employee->id,
            'provider_id'        => $this->provider->id,
            'arrival_date'       => now()->toDateString(),
            'buy_price_per_unit' => 5000,
            'qty_alive'          => 10,
        ])
        ->assertSessionHasErrors('building_id');

    expect(Batch::where('code', 'CAP-001')->exists())->toBeFalse();
});

test('la création d\'un lot caprin dans une chèvrerie est acceptée', function () {
    $chevre = \App\Models\Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $chevrerie = Building::factory()->create(['type' => 'chevrerie']);

    $this->actingAs($this->managerUser)
        ->post(route('batches.store'), [
            'code'               => 'CAP-002',
            'building_id'        => $chevrerie->id,
            'type'               => 'engraissement',
            'model_name'         => 'Chèvre Rousse Maradi',
            'species_id'         => $chevre->id,
            'employee_id'        => $this->employee->id,
            'provider_id'        => $this->provider->id,
            'arrival_date'       => now()->toDateString(),
            'buy_price_per_unit' => 5000,
            'qty_alive'          => 10,
        ])
        ->assertSessionDoesntHaveErrors();

    expect(Batch::where('code', 'CAP-002')->exists())->toBeTrue();
});

test('la modification d\'un lot vers un bâtiment incompatible avec son espèce est refusée', function () {
    $chevre = \App\Models\Species::firstOrCreate(
        ['slug' => 'chevre'],
        ['name_fr' => 'Chèvre / Caprin', 'family' => 'petit_ruminant', 'is_active' => true]
    );

    $chevrerie = Building::factory()->create(['type' => 'chevrerie']);
    $chairBuilding = Building::factory()->create(['type' => 'chair']);

    $batch = Batch::factory()->create([
        'species_id'         => $chevre->id,
        'building_id'        => $chevrerie->id,
        'status'             => 'Actif',
        'current_quantity'   => 10,
        'production_type_id' => ProductionType::resolveOrCreate('engraissement', $chevre->id)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('batches.update', $batch), [
            'type'               => 'engraissement',
            'building_id'        => $chairBuilding->id,
            'employee_id'        => $this->employee->id,
            'provider_id'        => $this->provider->id,
            'arrival_date'       => $batch->arrival_date->toDateString(),
            'buy_price_per_unit' => $batch->buy_price_per_unit,
            'status'             => $batch->status,
        ])
        ->assertSessionHasErrors('building_id');

    $batch->refresh();
    expect($batch->building_id)->toBe($chevrerie->id);
});

test('une vache peut être transférée vers une étable', function () {
    $vache = \App\Models\Species::firstOrCreate(
        ['slug' => 'vache'],
        ['name_fr' => 'Vache / Bovin', 'family' => 'grand_ruminant', 'is_active' => true]
    );

    $protocol = Protocol::create(['name' => 'Proto Bovin Laitière', 'type' => 'laitiere']);

    $etable1 = Building::factory()->create(['type' => 'etable']);
    $etable2 = Building::factory()->create(['type' => 'etable']);

    $batch = Batch::factory()->create([
        'species_id'         => $vache->id,
        'building_id'        => $etable1->id,
        'status'             => 'Actif',
        'current_quantity'   => 10,
        'production_type_id' => ProductionType::resolveOrCreate('engraissement', $vache->id)->id,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('batches.transfer', $batch), [
            'target_building_id' => $etable2->id,
            'new_protocol_id'    => $protocol->id,
            'new_phase'          => 'laitiere',
            'transfer_date'      => now()->toDateString(),
        ])
        ->assertSessionDoesntHaveErrors();

    $batch->refresh();
    expect($batch->type)->toBe('laitiere');
    expect($batch->building_id)->toBe($etable2->id);
});