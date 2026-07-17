<?php

use App\Models\Module;
use App\Models\Role;
use App\Models\SlaughterOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * RBAC Abattoir (Transformation, règles HACCP) : le point saillant est que le
 * BLOCAGE qualité d'un ordre relève du niveau M (opérateur) tandis que la
 * LIBÉRATION est réservée au niveau S (« qualité »). Un opérateur ne doit
 * jamais pouvoir lever un blocage. Module déjà cloisonné — recette de
 * non-régression sur les trois couches.
 */

function abattoirRole(string $name, array $perms): Role
{
    $role = Role::firstOrCreate(['name' => $name], ['label' => ucfirst($name), 'display_name' => ucfirst($name), 'permissions' => $perms]);
    $mod = Module::where('slug', 'abattoir')->value('id');
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

    // Opérateur d'abattoir : L+C+M, PAS de S (ne peut pas libérer un blocage).
    $this->operateur = User::factory()->create(['role_id' => abattoirRole('operateur_abattoir', ['L', 'C', 'M'])->id]);
    // Responsable qualité : L+S (autorité de libération HACCP).
    $this->qualite = User::factory()->create(['role_id' => abattoirRole('qualite_abattoir', ['L', 'S'])->id]);
    // Simple lecteur : L seul.
    $this->lecteur = User::factory()->create(['role_id' => abattoirRole('lecteur_abattoir', ['L'])->id]);

    $this->order = SlaughterOrder::create([
        'farm_id'          => $this->farm->id,
        'order_number'     => 'ABT-RBAC-001',
        'planned_date'     => now()->toDateString(),
        'planned_quantity' => 100,
        'status'           => 'bloque', // bloqué par la qualité
        'requested_by'     => $this->operateur->id,
    ]);
});

test("l'opérateur (M) ne peut PAS libérer un ordre bloqué (release = S)", function () {
    $this->actingAs($this->operateur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->patch(route('slaughter.orders.release', $this->order), ['reason' => 'Je force la libération'])
        ->assertRedirect();

    // Le blocage HACCP tient : l'ordre reste bloqué.
    expect($this->order->fresh()->status)->toBe('bloque');
});

test('le responsable qualité (S) peut libérer un ordre bloqué', function () {
    $this->actingAs($this->qualite)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->patch(route('slaughter.orders.release', $this->order), ['reason' => 'Analyses conformes, lot libéré'])
        ->assertRedirect();

    // L'ordre reprend son cours (plus bloqué).
    expect($this->order->fresh()->status)->not->toBe('bloque');
});

test("l'opérateur (M) PEUT bloquer un ordre planifié (block = M)", function () {
    $planifie = SlaughterOrder::create([
        'farm_id'          => $this->farm->id,
        'order_number'     => 'ABT-RBAC-002',
        'planned_date'     => now()->toDateString(),
        'planned_quantity' => 50,
        'status'           => 'planifie',
        'requested_by'     => $this->operateur->id,
    ]);

    $this->actingAs($this->operateur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->patch(route('slaughter.orders.block', $planifie), ['reason' => 'Suspicion sanitaire'])
        ->assertRedirect();

    expect($planifie->fresh()->status)->toBe('bloque');
});

test('un simple lecteur (L) ne peut PAS ouvrir la création d\'ordre (create = C)', function () {
    $response = $this->actingAs($this->lecteur)
        ->withSession(['current_farm_id' => $this->farm->id])
        ->get(route('slaughter.orders.create'));

    expect($response->status())->toBeIn([302, 403]);
});
