<?php

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $manager = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M']]);
    $viewer  = Role::firstOrCreate(['name' => 'viewer'], ['label' => 'Viewer', 'display_name' => 'Viewer', 'permissions' => ['L']]);

    $now = now();
    foreach ([[$manager, true], [$viewer, false]] as [$role, $write]) {
        foreach (Module::pluck('id') as $moduleId) {
            DB::table('module_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'module_id' => $moduleId],
                ['can_read' => true, 'can_create' => $write, 'can_modify' => $write, 'can_delete' => false, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    $this->manager = User::factory()->create(['role_id' => $manager->id]);
    $this->viewer  = User::factory()->create(['role_id' => $viewer->id]);

    $this->y = now()->year;
    $this->m = now()->month;
    $this->day = now()->startOfMonth()->toDateString();
});

test('un manager crée, met à jour et supprime les budgets du mois', function () {
    // Création
    $this->actingAs($this->manager)->post(route('budgets.store'), [
        'year' => $this->y, 'month' => $this->m,
        'budgets' => ['carburant' => 500000, 'transport' => 100000],
    ])->assertRedirect();

    expect((float) Budget::where('category', 'carburant')->forPeriod($this->y, $this->m)->value('amount'))->toBe(500000.0)
        ->and(Budget::forPeriod($this->y, $this->m)->count())->toBe(2);

    // Mise à jour
    $this->actingAs($this->manager)->post(route('budgets.store'), [
        'year' => $this->y, 'month' => $this->m, 'budgets' => ['carburant' => 650000],
    ])->assertRedirect();
    expect((float) Budget::where('category', 'carburant')->forPeriod($this->y, $this->m)->value('amount'))->toBe(650000.0);

    // Suppression (montant 0)
    $this->actingAs($this->manager)->post(route('budgets.store'), [
        'year' => $this->y, 'month' => $this->m, 'budgets' => ['carburant' => 0],
    ])->assertRedirect();
    expect(Budget::where('category', 'carburant')->forPeriod($this->y, $this->m)->exists())->toBeFalse();
});

test('le récap rapproche le budget des dépenses VALIDÉES du mois (les autres sont ignorées)', function () {
    Budget::create(['category' => 'carburant', 'year' => $this->y, 'month' => $this->m, 'amount' => 500000]);

    Expense::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->manager->id, 'category' => 'carburant', 'status' => 'valide', 'amount' => 200000, 'expense_date' => $this->day]);
    Expense::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->manager->id, 'category' => 'carburant', 'status' => 'en_attente', 'amount' => 999999, 'expense_date' => $this->day]); // ignorée

    $this->actingAs($this->manager)->get(route('budgets.index', ['year' => $this->y, 'month' => $this->m]))
        ->assertOk()
        ->assertSee('value="500000"', false) // budget saisi
        ->assertSee('200 000', false)        // dépensé = validé uniquement
        ->assertSee('40%', false);           // 200000 / 500000
});

test('un dépassement de budget est signalé (taux > 100%)', function () {
    Budget::create(['category' => 'transport', 'year' => $this->y, 'month' => $this->m, 'amount' => 100000]);
    Expense::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->manager->id, 'category' => 'transport', 'status' => 'valide', 'amount' => 150000, 'expense_date' => $this->day]);

    $this->actingAs($this->manager)->get(route('budgets.index', ['year' => $this->y, 'month' => $this->m]))
        ->assertOk()
        ->assertSee('150%', false);
});

test('un viewer (L) ne peut pas définir de budget', function () {
    $this->actingAs($this->viewer)->post(route('budgets.store'), [
        'year' => $this->y, 'month' => $this->m, 'budgets' => ['carburant' => 500000],
    ]);

    expect(Budget::where('category', 'carburant')->exists())->toBeFalse();
});

test('l\'export CSV contient le récap du mois', function () {
    Budget::create(['category' => 'carburant', 'year' => $this->y, 'month' => $this->m, 'amount' => 500000]);
    Expense::factory()->create(['farm_id' => $this->farm->id, 'user_id' => $this->manager->id, 'category' => 'carburant', 'status' => 'valide', 'amount' => 200000, 'expense_date' => $this->day]);

    $resp = $this->actingAs($this->manager)->get(route('budgets.export', ['year' => $this->y, 'month' => $this->m]))->assertOk();

    expect($resp->headers->get('content-type'))->toContain('text/csv');

    $csv = $resp->streamedContent();
    expect($csv)->toContain('Gasoil')
        ->toContain('500 000')
        ->toContain('200 000');
});
