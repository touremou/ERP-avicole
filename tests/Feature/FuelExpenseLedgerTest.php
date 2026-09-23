<?php

use App\Models\EnergyReading;
use App\Models\EnergySource;
use App\Models\Expense;
use App\Models\FuelPurchase;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $manager = Role::firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]
    );

    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $manager->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    $this->manager = User::factory()->create(['role_id' => $manager->id]);

    $this->source = EnergySource::create([
        'name' => 'Groupe Test', 'type' => 'groupe',
        'depreciation_years' => 5, 'total_hours_run' => 0, 'maintenance_interval_hours' => 250,
        'status' => 'operationnel', 'is_active' => true,
        'fuel_tank_capacity' => 1000, 'current_fuel_level' => 100,
    ]);
});

test('un achat de gasoil poste une dépense carburant valide et liée', function () {
    $this->actingAs($this->manager)
        ->post(route('utilities.fuel.store'), [
            'energy_source_id' => $this->source->id,
            'purchase_date'    => now()->toDateString(),
            'quantity_liters'  => 50,
            'unit_price'       => 1000,
            'supplier'         => 'Total',
        ])
        ->assertSessionHasNoErrors();

    $purchase = FuelPurchase::withoutGlobalScopes()->latest('id')->first();
    expect($purchase)->not->toBeNull()
        ->and($purchase->expense_id)->not->toBeNull();

    $expense = Expense::withoutGlobalScopes()->find($purchase->expense_id);
    expect($expense->category)->toBe('carburant')
        ->and($expense->status)->toBe('valide')
        ->and((float) $expense->amount)->toBe(50000.0); // 50 L × 1000

    // La cuve a bien été remplie (volet opérationnel conservé).
    expect((float) $this->source->fresh()->current_fuel_level)->toBe(150.0);
});

test('modifier un achat répercute le montant sur la dépense liée', function () {
    $this->actingAs($this->manager)->post(route('utilities.fuel.store'), [
        'energy_source_id' => $this->source->id,
        'purchase_date'    => now()->toDateString(),
        'quantity_liters'  => 50, 'unit_price' => 1000,
    ])->assertSessionHasNoErrors();

    $purchase = FuelPurchase::withoutGlobalScopes()->latest('id')->first();

    /*
     * La charge utile porte AUSSI la cuve et la date : le formulaire les rend
     * en champs `required` pré-remplis, donc le navigateur les envoie toujours.
     * Les omettre ici ne passait que parce que le contrôleur les ignorait — le
     * défaut corrigé par ailleurs. L'intention de ce test — la répercussion du
     * montant, sans doublon de dépense — est inchangée.
     */
    $this->actingAs($this->manager)->put(route('utilities.fuel.update', $purchase), [
        'energy_source_id' => $this->source->id,
        'purchase_date'    => now()->toDateString(),
        'quantity_liters'  => 80, 'unit_price' => 1000,
    ])->assertSessionHasNoErrors();

    $expense = Expense::withoutGlobalScopes()->find($purchase->expense_id);
    expect((float) $expense->amount)->toBe(80000.0); // 80 L × 1000

    // Toujours une seule dépense carburant : pas de doublon à la modification.
    expect(Expense::withoutGlobalScopes()->where('category', 'carburant')->count())->toBe(1);
});

test('supprimer un achat ANNULE la dépense liée, sans la retirer du registre', function () {
    /*
     * CE TEST A CHANGÉ D'INTENTION, DÉLIBÉRÉMENT.
     *
     * Il figeait le soft-delete de la dépense : traçable en base, mais SORTIE du
     * registre — invisible à qui consulte les dépenses. `ExpenseController::destroy`
     * refuse pourtant ce geste sur une dépense validée, et prescrit l'annulation :
     * « L'annulation garde la pièce, sa trace et son motif. » La dépense d'un achat
     * de carburant est toujours validée ; la règle s'appliquait donc, et ce chemin
     * passait à côté en appelant le modèle directement.
     *
     * La pièce reste désormais VISIBLE au registre, au statut « annule ». L'effet
     * comptable est le même — la charge quitte le P&L, qui ne compte que les
     * dépenses validées — mais on peut encore répondre à « qu'est-ce qui a été
     * annulé, pour combien, chez qui ».
     */
    $this->actingAs($this->manager)->post(route('utilities.fuel.store'), [
        'energy_source_id' => $this->source->id,
        'purchase_date'    => now()->toDateString(),
        'quantity_liters'  => 50, 'unit_price' => 1000,
    ])->assertSessionHasNoErrors();

    $purchase = FuelPurchase::withoutGlobalScopes()->latest('id')->first();
    $expenseId = $purchase->expense_id;

    $this->actingAs($this->manager)->delete(route('utilities.fuel.destroy', $purchase))
        ->assertSessionHasNoErrors();

    $expense = Expense::withoutGlobalScopes()->find($expenseId);

    expect($expense)->not->toBeNull()
        ->and($expense->trashed())->toBeFalse()      // plus de soft-delete
        ->and($expense->status)->toBe('annule')      // mais hors du P&L
        ->and((float) Expense::withoutGlobalScopes()->validated()->sum('amount'))->toBe(0.0);
});

test('le rapport de résultat ne compte le gasoil qu\'une seule fois', function () {
    $this->actingAs($this->manager)->post(route('utilities.fuel.store'), [
        'energy_source_id' => $this->source->id,
        'purchase_date'    => now()->toDateString(),
        'quantity_liters'  => 50, 'unit_price' => 1000,
    ])->assertSessionHasNoErrors();

    $response = $this->actingAs($this->manager)->get(route('reports.profit_loss', [
        'from' => now()->startOfMonth()->toDateString(),
        'to'   => now()->endOfMonth()->toDateString(),
    ]));
    $response->assertOk();

    $costs = $response->viewData('costs');
    // Poste « Carburant » présent au bon montant…
    expect((float) ($costs['Carburant'] ?? 0))->toBe(50000.0)
        // …et SANS ligne « Dépenses : Carburant » en double.
        ->and($costs)->not->toHaveKey('Dépenses : Carburant');
});

test('le carburant des groupes n\'est pas compté deux fois (P&L base achats)', function () {
    // Achat de carburant → ligne « Carburant » (registre des dépenses).
    $this->actingAs($this->manager)->post(route('utilities.fuel.store'), [
        'energy_source_id' => $this->source->id,
        'purchase_date'    => now()->toDateString(),
        'quantity_liters'  => 50, 'unit_price' => 1000, // 50 000
    ])->assertSessionHasNoErrors();

    // Relevé du GROUPE : son coût est une estimation du gasoil brûlé (ne doit PAS
    // entrer dans la ligne Énergie, sinon le carburant compterait deux fois).
    EnergyReading::create([
        'farm_id' => session('current_farm_id'), 'energy_source_id' => $this->source->id,
        'reading_date' => now()->toDateString(), 'hours_run' => 8, 'cost' => 30000,
        'user_id' => $this->manager->id,
    ]);

    // Relevé EDG (réseau) : vraie facture cash → DOIT compter dans Énergie.
    $edg = EnergySource::create([
        'name' => 'Réseau EDG', 'type' => 'edg',
        'depreciation_years' => 5, 'total_hours_run' => 0, 'maintenance_interval_hours' => 250,
        'status' => 'operationnel', 'is_active' => true,
    ]);
    EnergyReading::create([
        'farm_id' => session('current_farm_id'), 'energy_source_id' => $edg->id,
        'reading_date' => now()->toDateString(), 'hours_run' => 6, 'cost' => 18000,
        'user_id' => $this->manager->id,
    ]);

    $costs = $this->actingAs($this->manager)->get(route('reports.profit_loss', [
        'from' => now()->startOfMonth()->toDateString(),
        'to'   => now()->endOfMonth()->toDateString(),
    ]))->viewData('costs');

    expect((float) ($costs['Carburant'] ?? 0))->toBe(50000.0)            // achats uniquement
        ->and((float) ($costs['Énergie réseau (EDG)'] ?? 0))->toBe(18000.0); // EDG seul, groupe exclu
});
