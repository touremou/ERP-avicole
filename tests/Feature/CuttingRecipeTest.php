<?php

use App\Models\Batch;
use App\Models\CuttingRecipe;
use App\Models\CuttingRecipeLine;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Services\ButcheryNomenclature;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Lot 2 (refonte désassemblage) — recettes de désassemblage (BOM inversée) :
 * seed depuis la nomenclature, résolution recette-d'abord (repli config),
 * déchets pesés hors stock (balance de masse), garde-fou Σ rendements ≤ 100 %.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);
});

test('sans recette, la nomenclature reste le repli (rétrocompat)', function () {
    $cuts = ButcheryNomenclature::effectiveCutsForSpecies(null);

    expect(array_column($cuts, 'code'))->toContain('cuisse', 'aile', 'poitrine')
        ->and($cuts[0]['output_type'])->toBe(CuttingRecipeLine::TYPE_CO_PRODUIT)
        ->and($cuts[0]['expected_yield_percent'])->toBeNull();
});

test('le seed matérialise la nomenclature en recette éditable + ligne déchet', function () {
    $this->post(route('slaughter.recipes.seed', 'volaille'))->assertRedirect();

    $recipe = CuttingRecipe::activeFor('volaille');
    expect($recipe)->not->toBeNull()
        ->and($recipe->lines->pluck('cut_code'))->toContain('cuisse', 'dechet')
        // Les abats sont typés sous-produits, le reste co-produits.
        ->and($recipe->lines->firstWhere('cut_code', 'abats')->output_type)->toBe(CuttingRecipeLine::TYPE_SOUS_PRODUIT)
        ->and($recipe->lines->firstWhere('cut_code', 'dechet')->output_type)->toBe(CuttingRecipeLine::TYPE_DECHET);
});

test('la recette active prime sur la nomenclature (codes et attributs recette)', function () {
    $recipe = CuttingRecipe::seedFromNomenclature('volaille', $this->farm->id);
    $recipe->lines()->create([
        'cut_code' => 'pilon', 'label' => 'Pilons', 'output_type' => 'co_produit',
        'expected_yield_percent' => 12.5, 'default_destination' => 'stock_frais',
        'is_default' => true, 'sort_order' => 99,
    ]);

    $cuts = ButcheryNomenclature::effectiveCutsForSpecies(null);
    $pilon = collect($cuts)->firstWhere('code', 'pilon');

    expect($pilon)->not->toBeNull()
        ->and($pilon['expected_yield_percent'])->toBe(12.5)
        ->and(ButcheryNomenclature::effectiveCutCodesForSpecies(null))->toContain('pilon', 'autre', 'dechet');
});

test('une recette INACTIVE ne prime pas (repli nomenclature)', function () {
    $recipe = CuttingRecipe::seedFromNomenclature('volaille', $this->farm->id);
    $recipe->update(['is_active' => false]);

    expect(collect(ButcheryNomenclature::effectiveCutsForSpecies(null))->firstWhere('code', 'dechet'))->toBeNull();
});

test('garde-fou : Σ rendements attendus > 100 % refusé', function () {
    $recipe = CuttingRecipe::seedFromNomenclature('volaille', $this->farm->id);
    $lines = $recipe->lines->mapWithKeys(fn ($l) => [$l->id => [
        'label' => $l->label, 'output_type' => $l->output_type,
        'expected_yield_percent' => 30, // 9 lignes × 30 % = 270 %
        'default_destination' => $l->default_destination,
        'is_default' => $l->is_default ? 1 : 0,
    ]])->all();

    $this->put(route('slaughter.recipes.update', $recipe), [
        'name' => $recipe->name, 'is_active' => 1, 'lines' => $lines,
    ])->assertSessionHasErrors('lines');
});

test('découpe avec déchet pesé : balance de masse OK, déchet jamais en stock, coût sur les kg valorisables', function () {
    $batch = Batch::factory()->create([
        'code' => 'CHAIR-BOM', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
        'buy_price_per_unit' => 20000, // coût vif : 30 sujets × 20 000
    ]);
    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 30,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 60,
        'total_carcass_weight_kg' => 40, 'execution_date' => now()->toDateString(),
    ]);
    $order->refresh();

    // 20 kg découpés : 12 kg cuisses + 5 kg abats + 3 kg déchets déclarés (os).
    $this->post(route('slaughter.cutting.store', $order), [
        'total_input_kg' => 20, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 12, 'destination' => 'stock_frais'],
            ['type' => 'abats',  'name' => 'Abats',   'kg' => 5,  'destination' => 'stock_frais'],
            ['type' => 'dechet', 'name' => 'Déchets (os, parures)', 'kg' => 3, 'destination' => 'dechet'],
        ],
    ])->assertRedirect(route('slaughter.dashboard'))->assertSessionHas('success');

    // Le déchet n'entre JAMAIS en stock produits finis.
    expect(FinishedProduct::where('product_type', 'dechet')->exists())->toBeFalse();

    // Coût : carcasse 600 000 GNF / 40 kg = 15 000/kg → 20 kg engagés = 300 000,
    // répartis sur les 17 kg VALORISABLES (12+5, déchets exclus) ≈ 17 647,06/kg.
    $cuisses = FinishedProduct::where('product_type', 'cuisse')->first();
    expect((float) $cuisses->unit_cost)->toEqualWithDelta(300000 / 17, 0.5);

    // Balance de masse : perte inexpliquée = 20 − (12+5+3) = 0 %.
    $session = $order->cuttingSessions()->first();
    expect((float) $session->loss_percent)->toEqualWithDelta(0.0, 0.1);
});

test('la découpe accepte les codes personnalisés de la recette active', function () {
    $recipe = CuttingRecipe::seedFromNomenclature('volaille', $this->farm->id);
    $recipe->lines()->create([
        'cut_code' => 'pilon', 'label' => 'Pilons', 'output_type' => 'co_produit',
        'default_destination' => 'stock_frais', 'is_default' => false, 'sort_order' => 99,
    ]);

    $batch = Batch::factory()->create([
        'code' => 'CHAIR-PILON', 'initial_quantity' => 50, 'current_quantity' => 50, 'qty_alive' => 50,
    ]);
    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 10,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity' => 10, 'total_live_weight_kg' => 20,
        'total_carcass_weight_kg' => 14, 'execution_date' => now()->toDateString(),
    ]);
    $order->refresh();

    $this->post(route('slaughter.cutting.store', $order), [
        'total_input_kg' => 10, 'session_date' => now()->toDateString(),
        'products' => [
            ['type' => 'pilon', 'name' => 'Pilons', 'kg' => 8, 'destination' => 'stock_frais'],
        ],
    ])->assertRedirect(route('slaughter.dashboard'))->assertSessionHas('success');

    expect(FinishedProduct::where('product_type', 'pilon')->exists())->toBeTrue();
});

test("le formulaire de découpe signale la recette active", function () {
    $batch = Batch::factory()->create([
        'code' => 'CHAIR-FORM', 'initial_quantity' => 50, 'current_quantity' => 50, 'qty_alive' => 50,
    ]);
    $order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 10,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    app(SlaughterService::class)->executeSlaughter($order, [
        'actual_quantity' => 10, 'total_live_weight_kg' => 20,
        'total_carcass_weight_kg' => 14, 'execution_date' => now()->toDateString(),
    ]);
    $order->refresh();

    $this->get(route('slaughter.cutting.form', $order))->assertOk()->assertSee('Nomenclature std', false);

    CuttingRecipe::seedFromNomenclature('volaille', $this->farm->id);
    $this->get(route('slaughter.cutting.form', $order))->assertOk()->assertSee('Recette active', false);
});
