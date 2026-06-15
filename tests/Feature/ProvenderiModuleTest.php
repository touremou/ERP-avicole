<?php

use App\Actions\MillProduction\CompleteMillProduction;
use App\Models\Formula;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\Module;
use App\Models\Permission;
use App\Models\ProductionType;
use App\Models\RawMaterial;
use App\Models\Role;
use App\Models\Species;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
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
    $operator = $makeRole('operator', ['L', 'C']);

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
    $this->operatorUser = User::factory()->create(['role_id' => $operator->id]);
});

test('un opérateur peut ajouter une matière première', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('raw-materials.store'), [
            'name'            => 'Maïs jaune test',
            'unit'            => 'kg',
            'stock_qty'       => 500,
            'unit_cost'       => 3000,
            'alert_threshold' => 50,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(RawMaterial::where('name', 'Maïs jaune test')->exists())->toBeTrue();
});

test('un doublon de matière première est refusé avec un message explicite', function () {
    RawMaterial::factory()->create(['name' => 'Maïs jaune test']);

    $this->actingAs($this->operatorUser)
        ->from(route('raw-materials.index'))
        ->post(route('raw-materials.store'), [
            'name' => 'Maïs jaune test',
            'unit' => 'kg',
        ])
        ->assertRedirect(route('raw-materials.index'))
        ->assertSessionHasErrors(['name' => 'Une matière première porte déjà ce nom.']);

    expect(RawMaterial::where('name', 'Maïs jaune test')->count())->toBe(1);
});

test('un administrateur peut modifier la fiche d\'une matière première', function () {
    $material = RawMaterial::factory()->create([
        'name'      => 'Maïs jaune',
        'unit'      => 'kg',
        'stock_qty' => 100,
        'unit_cost' => 2500,
    ]);

    $this->actingAs($this->adminUser)
        ->put(route('raw-materials.update', $material->id), [
            'name'            => 'Maïs jaune (révisé)',
            'unit'            => 'KG',
            'stock_qty'       => 120,
            'unit_cost'       => 2700,
            'alert_threshold' => 60,
            'energy_kcal'     => 3300,
            'protein_rate'    => 8.5,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $material->refresh();
    expect($material->name)->toBe('Maïs jaune (révisé)')
        ->and($material->unit)->toBe('kg')
        ->and((float) $material->stock_qty)->toBe(120.0)
        ->and((float) $material->unit_cost)->toBe(2700.0);
});

test('un opérateur sans droit de modification ne peut pas modifier une matière première', function () {
    $material = RawMaterial::factory()->create();

    $this->actingAs($this->operatorUser)
        ->put(route('raw-materials.update', $material->id), [
            'name'      => 'Tentative',
            'unit'      => 'kg',
            'stock_qty' => 0,
            'unit_cost' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('la clôture de production crée le silo d\'aliment fini dans le bon secteur (multiespèces)', function () {
    $this->actingAs($this->adminUser);

    $chevreId = Species::where('slug', 'chevre')->value('id');
    $laitiere = ProductionType::resolveOrCreate('laitiere', $chevreId);

    $material = RawMaterial::factory()->create(['name' => 'Luzerne test', 'stock_qty' => 2000]);

    $formula = Formula::factory()->create([
        'name'               => 'CHÈVRE LAITIÈRE LACTATION',
        'species_id'         => $chevreId,
        'production_type_id' => $laitiere->id,
        'target_type'        => 'laitiere',
    ]);
    $formula->items()->create([
        'raw_material_id' => $material->id,
        'percentage'      => 100,
        'quantity_kg'     => 1000,
    ]);

    $production = MillProduction::factory()->create([
        'formula_id'        => $formula->id,
        'quantity_produced' => 500,
        'status'            => 'En cours',
    ]);

    app(CompleteMillProduction::class)->execute($production);

    // Le silo « Laitière Lactation » est provisionné et crédité de 500 kg.
    $silo = Stock::where('item_name', 'Laitière Lactation')
        ->where('category', Stock::CAT_CONSO)
        ->first();

    expect($silo)->not->toBeNull()
        ->and((float) $silo->current_quantity)->toBe(500.0)
        ->and($silo->getMeta('poultry_type'))->toBe('Laitière');

    // La matière première a été déstockée (100% × 500 kg).
    expect((float) $material->fresh()->stock_qty)->toBe(1500.0);
});

test('la clôture de production valorise le silo au coût de revient (CMP)', function () {
    $this->actingAs($this->adminUser);

    $chevreId = Species::where('slug', 'chevre')->value('id');
    $laitiere = ProductionType::resolveOrCreate('laitiere', $chevreId);

    // Matière première à 200 GNF/kg, 100% de la formule.
    $material = RawMaterial::factory()->create([
        'name'      => 'Maïs test CMP',
        'stock_qty' => 2000,
        'unit_cost' => 200,
    ]);

    $formula = Formula::factory()->create([
        'name'               => 'CHÈVRE LAITIÈRE LACTATION',
        'species_id'         => $chevreId,
        'production_type_id' => $laitiere->id,
        'target_type'        => 'laitiere',
    ]);
    $formula->items()->create([
        'raw_material_id' => $material->id,
        'percentage'      => 100,
        'quantity_kg'     => 1000,
    ]);

    $production = MillProduction::factory()->create([
        'formula_id'        => $formula->id,
        'quantity_produced' => 500,
        'status'            => 'En cours',
    ]);

    app(CompleteMillProduction::class)->execute($production);

    // 500 kg × 200 GNF de MP ⇒ coût de revient = 200 GNF/kg, reporté en CMP
    // sur l'article d'aliment fini (silo vide au départ).
    $silo = Stock::where('item_name', 'Laitière Lactation')
        ->where('category', Stock::CAT_CONSO)
        ->first();

    expect((float) $silo->fresh()->last_unit_price)->toBe(200.0);
    expect((float) $production->fresh()->real_cost_per_kg)->toBe(200.0);
});

test('suppression formule impossible si déjà produite (P-12)', function () {
    $formula = Formula::factory()->create();
    MillProduction::factory()->create(['formula_id' => $formula->id]);

    $this->actingAs($this->adminUser)
        ->delete(route('formulas.destroy', $formula))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('une machine sans historique de production peut être supprimée', function () {
    // Garde-fou de non-régression : destroy() s'appuyait sur une relation
    // pivotProductions() inexistante, ce qui provoquait un crash 500.
    $machine = MillMachine::factory()->create(['name' => 'Broyeur isolé']);

    $this->actingAs($this->adminUser)
        ->delete(route('machines.destroy', $machine->id))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(MillMachine::find($machine->id))->toBeNull();
});

test('une machine avec un historique de production ne peut pas être supprimée', function () {
    $machine = MillMachine::factory()->create(['name' => 'Broyeur productif']);
    MillProduction::factory()->create(['machine_id' => $machine->id]);

    $this->actingAs($this->adminUser)
        ->delete(route('machines.destroy', $machine->id))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(MillMachine::find($machine->id))->not->toBeNull();
});

test('la réinitialisation de maintenance d\'une machine est accessible et archive le compteur', function () {
    // Garde-fou : la route pointait vers resetMaintenance() (supprimée) au lieu
    // de reset(), provoquant un échec d'appel de méthode.
    $machine = MillMachine::factory()->create(['total_hours_run' => 180, 'status' => 'Maintenance']);

    $this->actingAs($this->adminUser)
        ->put(route('machines.reset', $machine->id), ['description' => 'Révision test'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $machine->refresh();
    expect((float) $machine->total_hours_run)->toBe(0.0)
        ->and($machine->status)->toBe('Opérationnel');
});

test('un opérateur (C, sans M) ne peut pas basculer le statut d\'une machine', function () {
    $machine = MillMachine::factory()->create(['status' => 'Opérationnel']);

    $this->actingAs($this->operatorUser)
        ->post(route('machines.toggle', $machine->id))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($machine->fresh()->status)->toBe('Opérationnel');
});
