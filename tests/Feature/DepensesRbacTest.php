<?php

use App\Models\Expense;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Dépenses/Trésorerie : un « saisisseur » (depenses.L + C) peut créer une
 * dépense mais NI l'approuver/annuler (M) NI la supprimer (S), et ne peut NI
 * définir les budgets (route budgets.store = M) NI voir le bouton d'édition
 * budgétaire (couche vue via $canEdit).
 */

function depensesRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'depenses')->value('id');
    DB::table('module_permissions')->updateOrInsert(
        ['role_id' => $role->id, 'module_id' => $mod],
        ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
         'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
         'created_at' => now(), 'updated_at' => now()]
    );

    return $role;
}

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    $this->saisisseur = User::factory()->create(['role_id' => depensesRole('saisisseur_dep', ['L', 'C'])->id]);

    $this->expense = Expense::create([
        'farm_id'        => $this->farm->id,
        'uuid'           => (string) Str::uuid(),
        'reference'      => 'DEP-RBAC-001',
        'user_id'        => $this->saisisseur->id,
        'category'       => 'carburant',
        'label'          => 'Gasoil groupe',
        'amount'         => 50000,
        'expense_date'   => now()->toDateString(),
        'payment_method' => 'especes',
        'status'         => 'en_attente',
    ]);
});

test('le saisisseur (C) ne peut PAS approuver une dépense (M)', function () {
    $this->actingAs($this->saisisseur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->put(route('expenses.approve', $this->expense))
        ->assertRedirect();

    expect($this->expense->fresh()->status)->toBe('en_attente');
});

test('le saisisseur (C) ne peut PAS supprimer une dépense (S)', function () {
    $this->actingAs($this->saisisseur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->delete(route('expenses.destroy', $this->expense))
        ->assertRedirect();

    expect(Expense::whereKey($this->expense->id)->exists())->toBeTrue();
});

test('le saisisseur (C) ne peut PAS définir un budget (route budgets.store = M)', function () {
    $response = $this->actingAs($this->saisisseur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->post(route('budgets.store'), ['year' => now()->year, 'month' => now()->month, 'budgets' => ['carburant' => 100000]]);

    // middleware can:depenses.M : refus (403 direct ou 302 via gestionnaire d'exceptions)
    expect($response->status())->toBeIn([302, 403]);
});

test("la vue budgets ne montre pas l'édition à un saisisseur (couche vue)", function () {
    $this->actingAs($this->saisisseur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('budgets.index'))
        ->assertOk()
        ->assertDontSee('Enregistrer les budgets')
        ->assertSee('Lecture seule'); // message explicite pour L
});

test('un manager (M) peut approuver la dépense', function () {
    $this->actingAs($this->managerUser)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->put(route('expenses.approve', $this->expense))
        ->assertRedirect();

    expect($this->expense->fresh()->status)->toBe('valide');
});
