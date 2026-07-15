<?php

use App\Models\Farm;
use App\Models\Module;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->farm = Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $this->farm->id]);

    $role = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'display_name' => 'Manager', 'permissions' => ['L', 'C', 'M', 'S']]);
    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }
    $this->manager = User::factory()->create(['role_id' => $role->id]);
});

test('les articles expédiables suivent les catégories de stock ACTIVES (pas codés en dur)', function () {
    Setting::set('stocks.categories', 'oeufs,litieres');

    $types = collect(Stock::shippableStockTypes())->pluck('type');

    expect($types)->toContain('oeufs')
        ->and($types)->toContain('litieres') // catégorie auparavant non expédiable
        ->and($types)->not->toContain('aliment'); // désactivée → absente
});

test('une litière (catégorie de stock) est expédiable et bien déstockée', function () {
    $stock = Stock::create([
        'farm_id' => $this->farm->id, 'category' => 'litieres', 'item_name' => 'Copeaux de bois',
        'unit' => 'sac', 'current_quantity' => 100, 'alert_threshold' => 10,
    ]);

    $this->actingAs($this->manager)
        ->post(route('dispatches.store'), [
            'driver_name'  => 'Test',
            'dispatch_date' => now()->toDateString(),
            'destination'  => 'Magasin Conakry',
            'items'        => [[
                'product_type' => 'litieres',
                'product_name' => 'Copeaux de bois',
                'product_id'   => $stock->id,
                'quantity'     => 10,
                'unit'         => 'sac',
                'condition'    => 'bon',
            ]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // La litière est sortie du stock (100 → 90).
    expect((float) $stock->fresh()->current_quantity)->toBe(90.0);
});
