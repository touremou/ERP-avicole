<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Cloisonnement Finance : Dépenses/Achats (saisie) vs Trésorerie (comptes,
 * soldes, virements). Un comptable saisit les dépenses sans voir les soldes
 * bancaires ; un trésorier gère les comptes sans saisir les dépenses.
 */

function finRole(string $name, array $moduleLevels): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => []]);
    foreach ($moduleLevels as $slug => $perms) {
        $mod = Module::where('slug', $slug)->value('id');
        if (! $mod) continue;
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $mod],
            ['can_read' => in_array('L', $perms), 'can_create' => in_array('C', $perms),
             'can_modify' => in_array('M', $perms), 'can_delete' => in_array('S', $perms),
             'created_at' => now(), 'updated_at' => now()]
        );
    }

    return $role;
}

beforeEach(function () {
    $this->setUpRbac();
    session(['current_farm_id' => $this->farm->id]);

    // Comptable : depenses L+C (saisie), PAS de trésorerie.
    $this->comptable = User::factory()->create([
        'role_id' => finRole('comptable', ['depenses' => ['L', 'C']])->id,
    ]);
    // Trésorier : tresorerie L+C, PAS de depenses.
    $this->tresorier = User::factory()->create([
        'role_id' => finRole('tresorier', ['tresorerie' => ['L', 'C']])->id,
    ]);
});

test('le comptable (depenses) saisit dépenses/achats/budgets mais ne voit PAS la trésorerie', function () {
    foreach (['expenses.index', 'budgets.index', 'purchases.index', 'finance.index'] as $route) {
        $this->actingAs($this->comptable)->withSession(['current_farm_id' => $this->farm->id])
            ->get(route($route))->assertOk();
    }
    foreach (['treasury.index', 'treasury.report'] as $route) {
        $response = $this->actingAs($this->comptable)->withSession(['current_farm_id' => $this->farm->id])
            ->get(route($route));
        expect($response->status())->toBeIn([302, 403], "Route trésorerie {$route} devrait être refusée au comptable");
    }
});

test('le hub Finance du comptable ne divulgue NI soldes NI comptes de trésorerie', function () {
    $this->actingAs($this->comptable)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('finance.index'))->assertOk()
        ->assertDontSee('Soldes par compte')
        ->assertDontSee('Comptes trésorerie')
        ->assertSee('Registre'); // accès Dépenses présent
});

test('le trésorier (tresorerie) gère les comptes mais ne peut PAS saisir de dépense', function () {
    $this->actingAs($this->tresorier)->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('treasury.index'))->assertOk();

    foreach (['expenses.index', 'budgets.index', 'purchases.index'] as $route) {
        $response = $this->actingAs($this->tresorier)->withSession(['current_farm_id' => $this->farm->id])
            ->get(route($route));
        expect($response->status())->toBeIn([302, 403], "Route dépenses {$route} devrait être refusée au trésorier");
    }
});

test('les tuiles du lanceur reflètent le cloisonnement Dépenses/Trésorerie', function () {
    $compSlugs = $this->comptable->getAccessibleModules()->pluck('slug');
    expect($compSlugs->contains('depenses'))->toBeTrue()
        ->and($compSlugs->contains('tresorerie'))->toBeFalse();

    $tresoSlugs = $this->tresorier->getAccessibleModules()->pluck('slug');
    expect($tresoSlugs->contains('tresorerie'))->toBeTrue()
        ->and($tresoSlugs->contains('depenses'))->toBeFalse();
});
