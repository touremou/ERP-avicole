<?php

use App\Models\Batch;
use App\Models\FinishedProduct;
use App\Models\Module;
use App\Models\ProductionType;
use App\Models\Role;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $farm = App\Models\Farm::firstOrCreate(['code' => 'FT-001'], ['name' => 'Ferme Test', 'is_active' => true]);
    session(['current_farm_id' => $farm->id]);

    $admin = Role::firstOrCreate(
        ['name' => 'admin'],
        ['label' => 'Admin', 'display_name' => 'Admin', 'permissions' => ['L', 'C', 'M', 'S']]
    );

    $now = now();
    foreach (Module::pluck('id') as $moduleId) {
        DB::table('module_permissions')->updateOrInsert(
            ['role_id' => $admin->id, 'module_id' => $moduleId],
            ['can_read' => true, 'can_create' => true, 'can_modify' => true, 'can_delete' => true, 'updated_at' => $now, 'created_at' => $now]
        );
    }

    $this->adminUser = User::factory()->create(['role_id' => $admin->id]);
});

function createSlaughterOrder(Batch $batch, int $qty = 100): SlaughterOrder
{
    return SlaughterOrder::create([
        'order_number'     => SlaughterOrder::generateNumber(),
        'batch_id'         => $batch->id,
        'planned_date'     => now()->toDateString(),
        'planned_quantity' => $qty,
        'status'           => 'planifie',
        'requested_by'     => 1,
    ]);
}

test('une incohérence poids vif / poids carcasse est rejetée avec une alerte claire (pas d\'erreur SQL)', function () {
    $batch = Batch::factory()->create(['current_quantity' => 200]);
    $order = createSlaughterOrder($batch, 100);

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.execute.store', $order), [
            'actual_quantity'         => 1,
            'total_live_weight_kg'    => 0.4,
            'total_carcass_weight_kg' => 80,
            'condemned_count'         => 0,
            'execution_date'          => now()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('total_carcass_weight_kg');

    expect(SlaughterResult::count())->toBe(0);
    $order->refresh();
    expect($order->status)->toBe('planifie');
});

test('un nombre de saisies sanitaires supérieur au nombre abattu est rejeté', function () {
    $batch = Batch::factory()->create(['current_quantity' => 200]);
    $order = createSlaughterOrder($batch, 100);

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.execute.store', $order), [
            'actual_quantity'         => 50,
            'total_live_weight_kg'    => 100,
            'total_carcass_weight_kg' => 70,
            'condemned_count'         => 60,
            'execution_date'          => now()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('condemned_count');

    expect(SlaughterResult::count())->toBe(0);
});

test('un abattage valide met en stock un produit nommé selon l\'espèce du lot (multiespèces)', function () {
    $chevreId = Species::where('slug', 'chevre')->value('id');
    $type = ProductionType::resolveOrCreate('engraissement', $chevreId);

    $batch = Batch::factory()->create([
        'production_type_id' => $type->id,
        'current_quantity'    => 50,
    ]);

    $order = createSlaughterOrder($batch, 10);

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.execute.store', $order), [
            'actual_quantity'         => 10,
            'total_live_weight_kg'    => 350,
            'total_carcass_weight_kg' => 175,
            'condemned_count'         => 0,
            'execution_date'          => now()->toDateString(),
        ])
        ->assertRedirect(route('slaughter.dashboard'))
        ->assertSessionHas('success');

    $result = SlaughterResult::first();
    expect($result)->not->toBeNull()
        ->and((float) $result->carcass_yield_percent)->toBe(50.0);

    $product = FinishedProduct::where('product_name', 'Chèvre / Caprin Entier Frais')->first();
    expect($product)->not->toBeNull()
        ->and((float) $product->current_quantity_kg)->toBe(175.0);

    expect(FinishedProduct::where('product_name', 'Poulet Entier Frais')->exists())->toBeFalse();
});

test('le rendement carcasse de référence dépend de la famille de l\'espèce', function () {
    $resolver = \App\Services\ButcheryNomenclature::class;

    $poulet = Species::where('slug', 'poulet')->first();
    $mouton = Species::where('slug', 'mouton')->first();
    $vache  = Species::where('slug', 'vache')->first();

    expect($resolver::carcassYieldForSpecies($poulet)['target_min'])->toBe(70)
        ->and($resolver::carcassYieldForSpecies($mouton)['target_min'])->toBe(45)
        ->and($resolver::carcassYieldForSpecies($vache)['target_min'])->toBe(52);

    // Repli volaille si espèce absente.
    expect($resolver::carcassYieldForSpecies(null)['target_min'])->toBe(70);
});

test('les morceaux de découpe proposés dépendent de la famille de l\'espèce', function () {
    $resolver = \App\Services\ButcheryNomenclature::class;

    $poulet = Species::where('slug', 'poulet')->first();
    $mouton = Species::where('slug', 'mouton')->first();
    $tilapia = Species::where('slug', 'tilapia')->first();

    $pouletCodes = array_column($resolver::cutsForSpecies($poulet), 'code');
    $moutonCodes = array_column($resolver::cutsForSpecies($mouton), 'code');
    $poissonCodes = array_column($resolver::cutsForSpecies($tilapia), 'code');

    expect($pouletCodes)->toContain('cuisse', 'aile', 'gesier')
        ->and($moutonCodes)->toContain('gigot', 'cotelettes', 'collier')
        ->and($poissonCodes)->toContain('filet', 'darne');

    // Un morceau ovin n'est pas valide pour de la volaille et inversement.
    expect($resolver::cutCodesForSpecies($poulet))->not->toContain('gigot')
        ->and($resolver::cutCodesForSpecies($mouton))->toContain('gigot', 'autre');
});

test('une découpe ovine enregistre des morceaux de boucherie spécifiques (gigot, épaule)', function () {
    $moutonId = Species::where('slug', 'mouton')->value('id');
    $type = ProductionType::resolveOrCreate('engraissement', $moutonId);
    $batch = Batch::factory()->create(['production_type_id' => $type->id, 'current_quantity' => 30]);

    $order = createSlaughterOrder($batch, 5);
    $order->update(['status' => 'termine']);
    SlaughterResult::create([
        'slaughter_order_id'      => $order->id,
        'total_carcass_weight_kg' => 100,
        'carcass_yield_percent'   => 48,
        'condemned_count'         => 0,
        'avg_live_weight_kg'      => 41.6,
        'avg_carcass_weight_kg'   => 20,
        'execution_date'          => now()->toDateString(),
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.cutting.store', $order), [
            'total_input_kg' => 100,
            'session_date'   => now()->toDateString(),
            'products'       => [
                ['type' => 'gigot',  'name' => 'Gigot',  'kg' => 30, 'destination' => 'stock_frais'],
                ['type' => 'epaule', 'name' => 'Épaule', 'kg' => 25, 'destination' => 'stock_frais'],
            ],
        ])
        ->assertRedirect(route('slaughter.dashboard'))
        ->assertSessionHas('success');

    expect(FinishedProduct::where('product_type', 'gigot')->where('product_name', 'Gigot')->exists())->toBeTrue()
        ->and(FinishedProduct::where('product_type', 'epaule')->exists())->toBeTrue();
});

test('un morceau de découpe incompatible avec l\'espèce est rejeté', function () {
    // Lot volaille : un "gigot" (ovin) ne doit pas être accepté.
    $batch = Batch::factory()->create(['current_quantity' => 100]);
    $order = createSlaughterOrder($batch, 10);
    $order->update(['status' => 'termine']);

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.cutting.store', $order), [
            'total_input_kg' => 20,
            'session_date'   => now()->toDateString(),
            'products'       => [
                ['type' => 'gigot', 'name' => 'Gigot', 'kg' => 10],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('products.0.type');
});

test('une découpe dont les morceaux dépassent l\'entrée est rejetée', function () {
    $batch = Batch::factory()->create(['current_quantity' => 100]);
    $order = createSlaughterOrder($batch, 10);
    $order->update(['status' => 'termine']);

    $this->actingAs($this->adminUser)
        ->post(route('slaughter.cutting.store', $order), [
            'total_input_kg' => 10,
            'session_date'   => now()->toDateString(),
            'products'       => [
                ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 8],
                ['type' => 'aile',   'name' => 'Ailes',   'kg' => 5],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('total_input_kg');
});

test('la page de création d\'ordre d\'abattage propose les lots de toutes les espèces', function () {
    $chevreId = Species::where('slug', 'chevre')->value('id');
    $caprinType = ProductionType::resolveOrCreate('engraissement', $chevreId);

    $chickenBatch = Batch::factory()->create(['current_quantity' => 100]);
    $goatBatch = Batch::factory()->create([
        'production_type_id' => $caprinType->id,
        'current_quantity'    => 20,
    ]);

    $response = $this->actingAs($this->adminUser)->get(route('slaughter.orders.create'));

    $response->assertOk();
    $response->assertSee($chickenBatch->code);
    $response->assertSee($goatBatch->code);
});
