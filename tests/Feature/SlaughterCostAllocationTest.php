<?php

use App\Models\Batch;
use App\Models\CuttingRecipe;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Models\Transformation;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Lot 3 (refonte désassemblage) — répartition des coûts conjoints par VALEUR
 * marchande relative + routage post-découpe vers une transformation enfant :
 *   coût_i /kg = coût_engagé × coef_i / Σ(kg_j × coef_j)
 * 1 kg de filet absorbe plus de coût qu'1 kg de pattes ; les déchets aucun.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);
    $this->service = app(SlaughterService::class);
});

/** Abattage 30 sujets × 20 000 GNF = 600 000 ; carcasse 40 kg → 15 000 /kg. */
function makeExecutedOrder($test, string $code): SlaughterOrder
{
    $batch = Batch::factory()->create([
        'code' => $code, 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
        'buy_price_per_unit' => 20000,
    ]);
    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 30,
        'status' => 'planifie', 'requested_by' => auth()->id(),
    ]);
    $test->service->executeSlaughter($order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 60,
        'total_carcass_weight_kg' => 40, 'execution_date' => now()->toDateString(),
    ]);

    return $order->fresh();
}

test('répartition par VALEUR : le morceau cher absorbe le coût, le vil presque rien', function () {
    // Recette : poitrine coef 40 000 /kg, abats coef 5 000 /kg.
    $recipe = CuttingRecipe::seedFromNomenclature('volaille', $this->farm->id);
    $recipe->lines->firstWhere('cut_code', 'poitrine')->update(['value_coefficient' => 40000]);
    $recipe->lines->firstWhere('cut_code', 'abats')->update(['value_coefficient' => 5000]);

    $order = makeExecutedOrder($this, 'CHAIR-VAL');

    // 20 kg engagés (300 000 GNF) : 10 kg poitrine + 8 kg abats + 2 kg déchets.
    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'poitrine', 'name' => 'Poitrine', 'kg' => 10, 'destination' => 'stock_frais'],
            ['type' => 'abats',    'name' => 'Abats',    'kg' => 8,  'destination' => 'stock_frais'],
            ['type' => 'dechet',   'name' => 'Déchets',  'kg' => 2,  'destination' => 'dechet'],
        ],
    ]);

    // Σ(kg×coef) = 10×40000 + 8×5000 = 440 000.
    // Poitrine : 300000×40000/440000 = 27 272,73 /kg ; abats : 3 409,09 /kg.
    $poitrine = FinishedProduct::where('product_type', 'poitrine')->first();
    $abats = FinishedProduct::where('product_type', 'abats')->first();
    expect((float) $poitrine->unit_cost)->toEqualWithDelta(27272.73, 0.5)
        ->and((float) $abats->unit_cost)->toEqualWithDelta(3409.09, 0.5);

    // Conservation : Σ(coût_i × kg_i) = coût engagé (300 000).
    $total = (float) $poitrine->unit_cost * 10 + (float) $abats->unit_cost * 8;
    expect($total)->toEqualWithDelta(300000, 5);

    // Le coût par ligne est aussi tracé sur le dossier (cut_products.unit_cost).
    $cp = $order->cuttingSessions->first()->products->firstWhere('product_type', 'poitrine');
    expect((float) $cp->unit_cost)->toEqualWithDelta(27272.73, 0.5);
});

test('repli au prix saisi comme coefficient quand la recette n\'en a pas', function () {
    $order = makeExecutedOrder($this, 'CHAIR-PRIX');

    // Pas de recette : prix saisis = coefficients (10 kg à 30 000, 10 kg à 10 000).
    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'poitrine', 'name' => 'Poitrine', 'kg' => 10, 'price' => 30000, 'destination' => 'stock_frais'],
            ['type' => 'abats',    'name' => 'Abats',    'kg' => 10, 'price' => 10000, 'destination' => 'stock_frais'],
        ],
    ]);

    // Σ = 10×30000+10×10000 = 400 000 → poitrine 300000×30000/400000 = 22 500 ; abats 7 500.
    expect((float) FinishedProduct::where('product_type', 'poitrine')->value('unit_cost'))->toEqualWithDelta(22500, 0.5)
        ->and((float) FinishedProduct::where('product_type', 'abats')->value('unit_cost'))->toEqualWithDelta(7500, 0.5);
});

test('repli au kg si une ligne valorisable n\'a ni coefficient ni prix', function () {
    $order = makeExecutedOrder($this, 'CHAIR-KG');

    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'poitrine', 'name' => 'Poitrine', 'kg' => 10, 'price' => 30000, 'destination' => 'stock_frais'],
            ['type' => 'abats',    'name' => 'Abats',    'kg' => 10, 'destination' => 'stock_frais'], // ni coef ni prix
        ],
    ]);

    // 300 000 / 20 kg valorisables = 15 000 /kg pour TOUTES les lignes.
    expect((float) FinishedProduct::where('product_type', 'poitrine')->value('unit_cost'))->toEqualWithDelta(15000, 0.5)
        ->and((float) FinishedProduct::where('product_type', 'abats')->value('unit_cost'))->toEqualWithDelta(15000, 0.5);
});

test('routage post-découpe : destination transformation → enfant en_cours lié, hors stock', function () {
    $order = makeExecutedOrder($this, 'CHAIR-ROUT');

    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'cuisse',   'name' => 'Cuisses',  'kg' => 12, 'price' => 25000, 'destination' => 'transformation'],
            ['type' => 'poitrine', 'name' => 'Poitrine', 'kg' => 8,  'price' => 25000, 'destination' => 'stock_frais'],
        ],
    ]);

    // La ligne routée ne crée PAS de stock, mais crée l'ordre enfant lié.
    expect(FinishedProduct::where('product_type', 'cuisse')->exists())->toBeFalse();

    $child = Transformation::where('slaughter_order_id', $order->id)->first();
    expect($child)->not->toBeNull()
        ->and($child->status)->toBe('en_cours')
        ->and((float) $child->input_kg)->toEqualWithDelta(12.0, 0.01)
        ->and($child->product_source)->toBe('Cuisses')
        // Coût matière figé : 300 000 réparti au prix identique → 15 000 /kg.
        ->and((float) $child->source_unit_cost)->toEqualWithDelta(15000, 0.5);

    // Pesée de sortie : 12 kg → 9 kg fumés, coût = 15 000×12/9 = 20 000 /kg.
    $this->service->completeTransformation($child, 9.0);
    $fume = FinishedProduct::where('product_name', 'Cuisses Autre')->first();
    expect((float) $fume->unit_cost)->toEqualWithDelta(20000, 0.5);

    // Cascade complète : l'enfant apparaît au dossier de lot.
    $this->get(route('slaughter.orders.traceability', $order))
        ->assertOk()
        ->assertSee('Transformations rattachées', false)
        ->assertSee($child->batch_number, false);
});

test('façon : aucun ordre enfant créé (matière du client, RG-07)', function () {
    $client = \App\Models\Client::create(['client_id' => 'CL-FACON-99', 'name' => 'Client Façon', 'type' => 'professionnel']);
    $reception = \App\Models\SlaughterReception::create([
        'provider_id' => \App\Models\Provider::factory()->create()->id,
        'reception_date' => now()->toDateString(),
        'received_quantity' => 50, 'total_live_weight_kg' => 90,
        'sanitary_state' => 'conforme', 'fasting_respected' => 'oui',
        'decision' => 'accepte', 'controller_id' => $this->managerUser->id, 'validated_at' => now(),
    ]);
    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'reception_id' => $reception->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 30,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
        'service_type' => 'facon', 'client_id' => $client->id,
        'billing_model' => 'par_sujet', 'billing_rate' => 1000,
    ]);
    $this->service->executeSlaughter($order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 60,
        'total_carcass_weight_kg' => 40, 'execution_date' => now()->toDateString(),
    ]);
    $order->refresh();

    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 15, 'destination' => 'transformation'],
        ],
    ]);

    expect(Transformation::where('slaughter_order_id', $order->id)->exists())->toBeFalse();
});

test('le dossier de lot affiche le coût de revient par produit de découpe', function () {
    $order = makeExecutedOrder($this, 'CHAIR-DOSS');

    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'poitrine', 'name' => 'Poitrine', 'kg' => 10, 'price' => 30000, 'destination' => 'stock_frais'],
            ['type' => 'abats',    'name' => 'Abats',    'kg' => 10, 'price' => 10000, 'destination' => 'stock_frais'],
        ],
    ]);

    $this->get(route('slaughter.orders.traceability', $order))
        ->assertOk()
        ->assertSee('Coût de revient par produit de découpe', false)
        ->assertSee('Marge/kg', false);
});

/*
 * Cohérence des coûts (rapport terrain) : les coûts de découpe du dossier
 * doivent venir du coût de L'ORDRE, jamais du CMUP du stock que les lots
 * précédents diluent — et le formulaire affiche la carcasse RESTANTE.
 */

test("le coût des découpes vient du coût de l'ordre, pas du CMUP dilué du stock", function () {
    // Stock historique de carcasses à coût NUL (avant Lot B) : il dilue le CMUP.
    FinishedProduct::create([
        'product_name' => 'Poulet Entier Frais', 'product_type' => 'entier_frais',
        'current_quantity_kg' => 100, 'current_quantity_pieces' => 50,
        'unit_price' => 0, 'unit_cost' => 0, 'storage_location' => 'frais',
    ]);

    $order = makeExecutedOrder($this, 'CHAIR-CMUP'); // coût 600 000, carcasse 40 kg → 15 000/kg

    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 16, 'destination' => 'stock_frais'],
        ],
    ]);

    // Coût de l'ordre : 15 000/kg × 20 kg = 300 000 sur 16 kg = 18 750/kg —
    // le CMUP dilué (600 000 / 140 kg ≈ 4 286/kg) ne doit PAS être utilisé.
    $cp = $order->cuttingSessions()->first()->products()->first();
    expect((float) $cp->unit_cost)->toEqualWithDelta(18750, 1);
});

test('cohérence dossier : Σ(coût ligne × kg) = coût gamme découpes', function () {
    $order = makeExecutedOrder($this, 'CHAIR-COH');

    $this->service->executeCutting($order, [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'poitrine', 'name' => 'Poitrine', 'kg' => 10, 'price' => 30000, 'destination' => 'stock_frais'],
            ['type' => 'abats',    'name' => 'Abats',    'kg' => 8,  'price' => 10000, 'destination' => 'stock_frais'],
            ['type' => 'dechet',   'name' => 'Déchets',  'kg' => 2,  'destination' => 'dechet'],
        ],
    ]);

    $order->load('cuttingSessions.products', 'result', 'reception', 'batch');
    $eco = $order->economicSummary();
    $decoupes = collect($eco['gammes'])->firstWhere('label', 'Découpes');

    $sumLines = $order->cuttingSessions->flatMap->products
        ->sum(fn ($p) => (float) $p->unit_cost * (float) $p->quantity_kg);

    // Gamme découpes = coût/kg d'ordre × kg entrés = 15 000 × 20 = 300 000 ;
    // la répartition par valeur redistribue ce même total entre les lignes.
    expect($decoupes['cost'])->toEqualWithDelta(300000, 5)
        ->and($sumLines)->toEqualWithDelta(300000, 5);
});

test('le formulaire de découpe affiche la carcasse RESTANTE (pas le total)', function () {
    $order = makeExecutedOrder($this, 'CHAIR-REST'); // 40 kg de carcasse

    $this->service->executeCutting($order, [
        'total_input_kg' => 25, 'session_date' => now()->toDateString(),
        'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 20, 'destination' => 'stock_frais']],
    ]);

    $this->get(route('slaughter.cutting.form', $order))
        ->assertOk()
        ->assertSee('Carcasse restante à découper', false)
        ->assertSee('15.0 kg / 40.0 kg', false);
});
