<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Stock;
use App\Models\StockMovement;
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
});

// ── PERMISSIONS ──

test('un visiteur (L) peut lister les stocks', function () {
    $this->actingAs($this->readonlyUser)
        ->get(route('stocks.index', ['category' => 'oeufs']))
        ->assertOk();
});

test('syncAll nécessite la permission S (admin)', function () {
    $this->actingAs($this->managerUser)
        ->post(route('stocks.syncAll'))
        ->assertRedirect();

    $this->actingAs($this->adminUser)
        ->post(route('stocks.syncAll'))
        ->assertRedirect()
        ->assertSessionHas('success');
});

// ── CRÉATION ──

test('créer un stock génère un mouvement initial si quantité > 0', function () {
    $this->actingAs($this->operatorUser)->post(route('stocks.store'), [
        'item_name'        => 'TestS',
        'category'         => 'oeufs',
        'unit'             => 'Alvéole',
        'alert_threshold'  => 5,
        'current_quantity' => 100,
    ]);

    $stock = Stock::where('item_name', 'TestS')->first();
    expect($stock)->not->toBeNull();
    expect(StockMovement::where('stock_id', $stock->id)->where('type', 'in')->exists())->toBeTrue();
});

test('anti-doublon : ne peut pas créer 2 stocks même nom+catégorie', function () {
    Stock::factory()->create(['item_name' => 'XL', 'category' => 'oeufs']);

    $this->actingAs($this->operatorUser)
        ->post(route('stocks.store'), [
            'item_name'       => 'XL',
            'category'        => 'oeufs',
            'unit'            => 'Alvéole',
            'alert_threshold' => 5,
        ])
        ->assertSessionHasErrors('item_name');
});

test('conversion Sac→KG pour catégorie conso', function () {
    $this->actingAs($this->operatorUser)->post(route('stocks.store'), [
        'item_name'        => 'Chair Finition',
        'category'         => 'conso',
        'unit'             => 'Sac',
        'alert_threshold'  => 2,
        'current_quantity' => 3,
    ]);

    $stock = Stock::where('item_name', 'Chair Finition')->first();
    expect($stock->unit)->toBe('KG');
    expect((float) $stock->current_quantity)->toBe(150.0);
    expect((float) $stock->alert_threshold)->toBe(100.0);
});

// ── MOUVEMENTS ──
// MoveStockRequest exige Gate::allows('M') → il faut un manager, pas un opérateur

test('mouvement entrée augmente le stock', function () {
    $stock = Stock::factory()->create(['current_quantity' => 100, 'unit' => 'KG', 'category' => 'conso']);

    $this->actingAs($this->managerUser)->post(route('stocks.move'), [
        'stock_id' => $stock->id, 'type' => 'in', 'quantity' => 50,
    ]);

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(150.0);
});

test('mouvement sortie décrémente le stock', function () {
    $stock = Stock::factory()->create(['current_quantity' => 100, 'unit' => 'KG', 'category' => 'conso']);

    $this->actingAs($this->managerUser)->post(route('stocks.move'), [
        'stock_id' => $stock->id, 'type' => 'out', 'quantity' => 30,
    ]);

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(70.0);
});

test('sortie impossible si stock insuffisant', function () {
    $stock = Stock::factory()->create(['current_quantity' => 10, 'unit' => 'KG', 'category' => 'conso']);

    $response = $this->actingAs($this->managerUser)
        ->from(route('stocks.index', ['category' => 'conso']))
        ->post(route('stocks.move'), [
            'stock_id' => $stock->id, 'type' => 'out', 'quantity' => 50,
        ]);

    $response->assertSessionHasErrors('quantity');
});

test('ajustement écrase la quantité et logge le delta (ST-04)', function () {
    $stock = Stock::factory()->create(['current_quantity' => 100, 'unit' => 'KG', 'category' => 'conso']);

    $this->actingAs($this->managerUser)->post(route('stocks.move'), [
        'stock_id' => $stock->id, 'type' => 'adjustment', 'quantity' => 80,
    ]);

    $stock->refresh();
    expect((float) $stock->current_quantity)->toBe(80.0);

    $movement = StockMovement::where('stock_id', $stock->id)->where('type', 'adjustment')->first();
    expect((float) $movement->quantity)->toBe(20.0);
});

// ── SUPPRESSION ──

test('suppression impossible si historique de mouvements (ST-03)', function () {
    $stock = Stock::factory()->create(['current_quantity' => 0, 'category' => 'conso']);
    StockMovement::factory()->create(['stock_id' => $stock->id, 'type' => 'in']);

    $this->actingAs($this->adminUser)
        ->delete(route('stocks.destroy', $stock->id))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('suppression possible si aucun mouvement', function () {
    $stock = Stock::factory()->create(['current_quantity' => 0, 'category' => 'conso']);

    $this->actingAs($this->adminUser)
        ->delete(route('stocks.destroy', $stock->id))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Stock::find($stock->id))->toBeNull();
});
