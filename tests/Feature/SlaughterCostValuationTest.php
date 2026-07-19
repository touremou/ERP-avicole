<?php

use App\Models\Batch;
use App\Models\FinishedProduct;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Lot B — valorisation des coûts au kg : le coût matière vif (lot interne)
 * descend sur la carcasse, se propage à la découpe et à la transformation
 * (+ production_cost), et la marge se ventile par gamme.
 */

beforeEach(function () {
    $this->setUpRbac();
    // Lot interne à coût d'acquisition connu : 100 sujets à 1 000 = 100 000.
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-COST', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
        'buy_price_per_unit' => 1000, 'total_acquisition_cost' => 100000,
    ]);
    $this->order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 60,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    $this->payload = [
        'actual_quantity' => 60, 'total_live_weight_kg' => 120,
        'total_carcass_weight_kg' => 90, 'execution_date' => now()->toDateString(),
    ];
    $this->service = app(SlaughterService::class);
    $this->actingAs($this->managerUser);
});

test('le coût du lot interne descend au kg sur la carcasse', function () {
    // 60 sujets × 1 000 = 60 000 de coût matière ; 90 kg carcasse → 666,67/kg.
    $this->service->executeSlaughter($this->order, $this->payload);

    $carcass = FinishedProduct::where('product_type', 'entier_frais')->first();
    expect((float) $carcass->unit_cost)->toBe(666.67);

    $eco = $this->order->fresh()->load('result', 'batch', 'cuttingSessions.products')->economicSummary();
    expect($eco['mode'])->toBe('interne')
        ->and($eco['cost'])->toBe(60000.0)
        ->and($eco['cost_per_kg'])->toBe(666.67);
});

test('la découpe propage le coût/kg aux morceaux (perte incluse)', function () {
    $this->service->executeSlaughter($this->order, $this->payload); // carcasse 90 kg @ 666,67/kg

    // 60 kg entrés → 54 kg de morceaux (6 kg de perte) : coût matière consommé
    // 60 × 666,67 = 40 000 réparti sur 54 kg → ~740,74/kg.
    $this->service->executeCutting($this->order->fresh(), [
        'total_input_kg' => 60,
        'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 54, 'price' => 2000]],
    ]);

    $cut = FinishedProduct::where('product_type', 'cuisse')->first();
    expect((float) $cut->unit_cost)->toBeGreaterThan(666.0)   // > coût carcasse (perte)
        ->and((float) $cut->unit_cost)->toBeLessThan(760.0);
});

test('la transformation intègre le production_cost dans le coût de revient', function () {
    $this->service->executeSlaughter($this->order, $this->payload);
    $this->service->executeCutting($this->order->fresh(), [
        'total_input_kg' => 30,
        'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 30, 'price' => 2000, 'destination' => 'stock_frais']],
    ]);
    $sourceCost = (float) FinishedProduct::where('product_type', 'cuisse')->value('unit_cost');

    // 20 kg cuisses transformées en fumé, 15 kg sortis, 30 000 de production.
    $this->service->executeTransformation([
        'product_source' => 'Cuisses', 'type' => 'fume',
        'input_kg' => 20, 'output_kg' => 15, 'cost' => 30000,
    ]);

    $fume = FinishedProduct::where('product_type', 'fume')->first();
    // coût attendu = (sourceCost × 20 + 30 000) / 15 → strictement > au coût source.
    $expected = round(($sourceCost * 20 + 30000) / 15, 2);
    expect((float) $fume->unit_cost)->toBe($expected)
        ->and((float) $fume->unit_cost)->toBeGreaterThan($sourceCost); // production_cost intégré
});

test('la marge se ventile par gamme (découpes valorisées vs coût alloué)', function () {
    $this->service->executeSlaughter($this->order, $this->payload);
    $this->service->executeCutting($this->order->fresh(), [
        'total_input_kg' => 90, // toute la carcasse découpée
        'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 80, 'price' => 2000]],
    ]);

    $eco = $this->order->fresh()->load('result', 'batch', 'cuttingSessions.products')->economicSummary();

    // Une gamme « Découpes » : valeur 80×2000 = 160 000, coût = 60 000 (tout le
    // matière), marge = 100 000.
    $decoupes = collect($eco['gammes'])->firstWhere('label', 'Découpes');
    expect($decoupes)->not->toBeNull()
        ->and($decoupes['value'])->toBe(160000.0)
        ->and($decoupes['cost'])->toBe(60000.0)
        ->and($decoupes['margin'])->toBe(100000.0);
    expect($eco['margin'])->toBe(100000.0);
});
