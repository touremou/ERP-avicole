<?php

use App\Models\Batch;
use App\Models\SlaughterByproduct;
use App\Models\SlaughterOrder;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Registre E9 auto-alimenté : à l'exécution de l'abattage, les sous-produits
 * non comestibles sont ESTIMÉS (poids vif × ratio zootechnique d'espèce),
 * méthode « estime » tracée — une pesée réelle reste possible et se distingue.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->managerUser);
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-EST', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
    ]);
    $this->order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 30,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
});

test("l'exécution estime les sous-produits volaille (vif × ratio), méthode tracée", function () {
    // 100 kg vif, 72 kg carcasse → sang 3,5 kg, plumes 7 kg, viscères 10 kg.
    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 100,
        'total_carcass_weight_kg' => 72, 'execution_date' => now()->toDateString(),
    ]);

    $byType = SlaughterByproduct::where('slaughter_order_id', $this->order->id)->get()->keyBy('type');
    expect($byType)->toHaveCount(3)
        ->and((float) $byType['sang']->quantity_kg)->toEqualWithDelta(3.5, 0.01)
        ->and((float) $byType['plumes']->quantity_kg)->toEqualWithDelta(7.0, 0.01)
        ->and((float) $byType['visceres']->quantity_kg)->toEqualWithDelta(10.0, 0.01)
        ->and($byType['sang']->method)->toBe('estime')
        ->and($byType['sang']->destination)->toBe('equarrissage');

    // Contrôle auto de clôture : le registre n'est plus vide.
    expect($this->order->fresh()->closureAutoChecks()['byproducts_recorded'])->toBeTrue();
});

test('plafond balance de masse : les estimations ne dépassent jamais (vif − carcasse)', function () {
    // Ratios volaille surchargés à l'excès via les réglages (50 % de sang).
    \App\Models\Setting::set('abattoir.byproduct_ratio_sang', '50');

    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 100,
        'total_carcass_weight_kg' => 80, 'execution_date' => now()->toDateString(),
    ]);

    $total = (float) SlaughterByproduct::where('slaughter_order_id', $this->order->id)->sum('quantity_kg');
    expect($total)->toBeLessThanOrEqual(20.01); // enveloppe = 100 − 80
});

test('une pesée réelle reste distincte (méthode pese) et coexiste avec les estimations', function () {
    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 100,
        'total_carcass_weight_kg' => 72, 'execution_date' => now()->toDateString(),
    ]);

    $this->post(route('slaughter.registres.sous_produits.store'), [
        'slaughter_order_id' => $this->order->id,
        'type' => 'plumes', 'quantity_kg' => 6.2, 'destination' => 'vente',
    ])->assertRedirect();

    $weighed = SlaughterByproduct::where('type', 'plumes')->where('method', 'pese')->first();
    expect($weighed)->not->toBeNull()
        ->and((float) $weighed->quantity_kg)->toEqualWithDelta(6.2, 0.01);
});

test('le registre affiche la méthode (badge Estimé / Pesé)', function () {
    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 100,
        'total_carcass_weight_kg' => 72, 'execution_date' => now()->toDateString(),
    ]);

    $this->get(route('slaughter.registres.sous_produits'))
        ->assertOk()
        ->assertSee('Estimé', false);
});

test('le dossier de lot marque les quantités estimées', function () {
    app(SlaughterService::class)->executeSlaughter($this->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 100,
        'total_carcass_weight_kg' => 72, 'execution_date' => now()->toDateString(),
    ]);

    $this->get(route('slaughter.orders.traceability', $this->order))
        ->assertOk()
        ->assertSee('estimé', false);
});
