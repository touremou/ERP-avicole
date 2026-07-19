<?php

use App\Models\Batch;
use App\Models\FinishedProduct;
use App\Models\FinishedProductAdjustment;
use App\Models\HealthIncident;
use App\Models\SlaughterOrder;
use App\Models\SlaughterResult;
use App\Models\Transformation;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Industrialisation du module Abattoir & Transformation (audit 2026-07-04).
 *
 * Mêmes familles d'invariants que les drills C1/C3/C5 :
 * - biosécurité : un lot en QUARANTAINE ne s'abat pas (ordre ET exécution) ;
 * - anti-rejeu : statut d'ordre re-contrôlé SOUS verrou (double-clic inoffensif) ;
 * - effectif re-contrôlé à l'exécution (le lot a pu maigrir depuis l'ordre) ;
 * - conservation de matière : Σ découpes ≤ carcasse produite ;
 * - transformation « en cours » terminable une seule fois ;
 * - ajustements/éliminations de produits finis JOURNALISÉS en base.
 */

beforeEach(function () {
    $this->setUpRbac();

    $this->batch = Batch::factory()->create([
        'code'             => 'CHAIR-ABT',
        'initial_quantity' => 100,
        'current_quantity' => 100,
        'qty_alive'        => 100,
    ]);

    $this->quarantine = function (): HealthIncident {
        return HealthIncident::create([
            'building_id'           => $this->batch->building_id,
            'batch_id'              => $this->batch->id,
            'user_id'               => $this->managerUser->id,
            'incident_date'         => now()->toDateString(),
            'mortality_count'       => 4,
            'symptoms'              => 'Mortalité brutale',
            'severity'              => HealthIncident::SEVERITY_CRITICAL,
            'status'                => HealthIncident::STATUS_PENDING,
            'is_quarantined'        => true,
            'quarantine_started_at' => now(),
        ]);
    };

    $this->makeOrder = function (int $qty = 60): SlaughterOrder {
        return SlaughterOrder::create([
            'order_number'     => SlaughterOrder::generateNumber(),
            'batch_id'         => $this->batch->id,
            'planned_date'     => now()->toDateString(),
            'planned_quantity' => $qty,
            'status'           => 'planifie',
            'requested_by'     => $this->managerUser->id,
        ]);
    };

    $this->executePayload = [
        'actual_quantity'         => 60,
        'total_live_weight_kg'    => 120,
        'total_carcass_weight_kg' => 90,
        'execution_date'          => now()->toDateString(),
    ];
});

// ─── RENDU DES PAGES (attrape les variables manquantes) ───

test('les pages du module abattoir se rendent, journal des ajustements inclus', function () {
    $product = FinishedProduct::create([
        'product_name' => 'Cuisses', 'product_type' => 'cuisse',
        'storage_location' => 'frais', 'unit' => 'kg', 'unit_price' => 0,
        'current_quantity_kg' => 25,
    ]);

    // Une écriture de journal pour rendre la table des ajustements.
    $this->actingAs($this->managerUser)
        ->post(route('slaughter.finished.adjust', $product), [
            'new_quantity_kg' => 20, 'reason' => 'Recomptage',
        ]);

    $this->actingAs($this->managerUser)->get(route('slaughter.dashboard'))->assertOk();
    $this->actingAs($this->managerUser)->get(route('slaughter.orders.create'))->assertOk();
    $this->actingAs($this->managerUser)
        ->get(route('slaughter.finished'))
        ->assertOk()
        ->assertSee('Recomptage'); // le journal s'affiche réellement
});

// ─── BIOSÉCURITÉ ───

test('ordre d\'abattage sur un lot en quarantaine : refusé', function () {
    ($this->quarantine)();

    $this->actingAs($this->managerUser)
        ->post(route('slaughter.orders.store'), [
            'batch_id'         => $this->batch->id,
            'planned_date'     => now()->toDateString(),
            'planned_quantity' => 50,
        ])
        ->assertSessionHasErrors('batch_id');

    expect(SlaughterOrder::count())->toBe(0);
});

test('quarantaine posée APRÈS l\'ordre : l\'exécution est refusée, rien ne bouge', function () {
    $order = ($this->makeOrder)();
    ($this->quarantine)(); // la quarantaine tombe entre l'ordre et l'abattage

    $this->actingAs($this->managerUser)
        ->post(route('slaughter.execute.store', $order), $this->executePayload)
        ->assertSessionHas('error');

    expect($this->batch->fresh()->current_quantity)->toBe(100);
    expect(SlaughterResult::count())->toBe(0);
    expect(FinishedProduct::count())->toBe(0);
    expect($order->fresh()->status)->toBe('planifie');
});

// ─── EFFECTIF & ANTI-REJEU ───

test('lot qui a maigri depuis l\'ordre : exécution au-delà de l\'effectif refusée', function () {
    $order = ($this->makeOrder)(80);
    $this->batch->update(['current_quantity' => 50]); // mortalité/ventes entre-temps

    $this->actingAs($this->managerUser)
        ->post(route('slaughter.execute.store', $order), array_merge($this->executePayload, [
            'actual_quantity' => 80,
        ]))
        ->assertSessionHas('error');

    expect($this->batch->fresh()->current_quantity)->toBe(50);
    expect(SlaughterResult::count())->toBe(0);
});

test('rejeu de l\'exécution (double-clic) : un seul décrément, une seule carcasse en stock', function () {
    $order = ($this->makeOrder)();

    $this->actingAs($this->managerUser)
        ->post(route('slaughter.execute.store', $order), $this->executePayload)
        ->assertSessionHas('success');

    // Rejeu identique (onglet resté ouvert)
    $this->actingAs($this->managerUser)
        ->post(route('slaughter.execute.store', $order), $this->executePayload)
        ->assertSessionHas('error');

    expect($this->batch->fresh()->current_quantity)->toBe(40); // 100 - 60, UNE fois
    expect(SlaughterResult::count())->toBe(1);

    $carcass = FinishedProduct::where('product_type', 'entier_frais')->first();
    expect((float) $carcass->current_quantity_kg)->toEqual(90.0); // pas 180
});

// ─── GAMMES DE SORTIE (PRÉSENTATION À L'EXÉCUTION) ───

test('la gamme choisie nomme l\'article de stock et est enregistrée (PAC / effilé / brut)', function () {
    $service = app(SlaughterService::class);
    $this->actingAs($this->managerUser);
    // 20 sujets par ordre → 3 × 20 = 60 ≤ 100 (effectif du lot).
    $payload = array_merge($this->executePayload, ['actual_quantity' => 20]);

    // PAC
    $result = $service->executeSlaughter(($this->makeOrder)(20), array_merge($payload, ['presentation' => 'pac']));
    expect($result->presentation)->toBe('pac');
    expect(FinishedProduct::where('product_name', 'like', '%PAC%')->exists())->toBeTrue();

    // Effilé
    $service->executeSlaughter(($this->makeOrder)(20), array_merge($payload, ['presentation' => 'effile']));
    expect(FinishedProduct::where('product_name', 'like', '%Effilé%')->exists())->toBeTrue();

    // Brut (défaut) → nom historique « Entier Frais »
    $r3 = $service->executeSlaughter(($this->makeOrder)(20), $payload);
    expect($r3->presentation)->toBe('brut');
    expect(FinishedProduct::where('product_name', 'like', '%Entier Frais%')->exists())->toBeTrue();
});

test('la bande de rendement de l\'effilé est plus haute que celle du brut (têtes/pattes conservées)', function () {
    $brut   = \App\Services\ButcheryNomenclature::presentationYieldBand('brut', null);
    $effile = \App\Services\ButcheryNomenclature::presentationYieldBand('effile', null);

    expect($effile['target_min'])->toBeGreaterThan($brut['target_min'])
        ->and($effile['alert_min'])->toBeGreaterThan($brut['alert_min']);
});

test('le formulaire web accepte la présentation et le sync la valide', function () {
    // Web : soumission avec présentation PAC.
    $order = ($this->makeOrder)();
    $this->actingAs($this->managerUser)
        ->post(route('slaughter.execute.store', $order), array_merge($this->executePayload, ['presentation' => 'pac']))
        ->assertRedirect();
    expect($order->fresh()->result->presentation)->toBe('pac');
});

// ─── CALIBRAGE & CONDITIONNEMENT (DÉCOUPE) ───

test('une découpe calibrée et conditionnée est tracée et distingue l\'UVC en stock', function () {
    $order = ($this->makeOrder)();
    $this->actingAs($this->managerUser);
    app(SlaughterService::class)->executeSlaughter($order, $this->executePayload); // carcasse 90 kg

    $session = app(SlaughterService::class)->executeCutting($order->fresh(), [
        'total_input_kg' => 40,
        'products' => [
            ['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 20, 'calibre' => 'M', 'packaging' => 'barquette', 'pack_count' => 12],
            ['type' => 'gesier', 'name' => 'Gésiers', 'kg' => 5, 'packaging' => 'sachet', 'pack_count' => 20],
        ],
    ]);

    // Traçabilité sur le produit de découpe.
    $cuisse = $session->products->firstWhere('product_type', 'cuisse');
    expect($cuisse->calibre)->toBe('M')
        ->and($cuisse->packaging)->toBe('barquette')
        ->and($cuisse->pack_count)->toBe(12);

    // Stock produit fini : nom ENRICHI → UVC distincte (calibre + conditionnement).
    expect(\App\Models\FinishedProduct::where('product_name', 'like', '%Cuisses · M · Barquette%')->exists())->toBeTrue();
    // Abats ensachés = gamme à part entière.
    expect(\App\Models\FinishedProduct::where('product_name', 'like', '%Gésiers · Sachet%')->exists())->toBeTrue();
});

test('le web accepte le calibre et le conditionnement à la découpe', function () {
    $order = ($this->makeOrder)();
    $this->actingAs($this->managerUser);
    app(SlaughterService::class)->executeSlaughter($order, $this->executePayload);

    $this->post(route('slaughter.cutting.store', $order->fresh()), [
        'session_date' => now()->toDateString(), 'total_input_kg' => 30,
        'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 25, 'calibre' => 'S', 'packaging' => 'barquette', 'pack_count' => 10]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(\App\Models\CutProduct::where('calibre', 'S')->where('packaging', 'barquette')->exists())->toBeTrue();
});

// ─── CONSERVATION DE MATIÈRE (DÉCOUPE) ───

test('la somme des découpes ne peut pas dépasser la carcasse produite', function () {
    $order = ($this->makeOrder)();
    $this->actingAs($this->managerUser);
    $service = app(SlaughterService::class);
    $service->executeSlaughter($order, $this->executePayload); // carcasse 90 kg

    $products = [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 50]];

    // Session 1 : 60 kg sur 90 → OK
    $service->executeCutting($order->fresh(), [
        'total_input_kg' => 60, 'session_date' => now()->toDateString(), 'products' => $products,
    ]);

    // Session 2 : 60 kg alors qu'il ne reste que 30 → REFUS
    expect(fn () => $service->executeCutting($order->fresh(), [
        'total_input_kg' => 60, 'session_date' => now()->toDateString(), 'products' => $products,
    ]))->toThrow(Exception::class, 'Conservation de matière');

    // Session 2 corrigée : 30 kg → OK (épuise exactement la carcasse)
    $session = $service->executeCutting($order->fresh(), [
        'total_input_kg' => 30, 'session_date' => now()->toDateString(),
        'products' => [['type' => 'aile', 'name' => 'Ailes', 'kg' => 25]],
    ]);
    expect($session)->not->toBeNull();
});

// ─── TRANSFORMATION ───

test('une transformation en cours se termine UNE fois : rendement + entrée en stock', function () {
    $this->actingAs($this->managerUser);
    $service = app(SlaughterService::class);

    FinishedProduct::create([
        'product_name' => 'Poulet Entier Frais', 'product_type' => 'entier_frais',
        'storage_location' => 'frais', 'unit' => 'kg', 'unit_price' => 0,
        'current_quantity_kg' => 40,
    ]);

    // Engagement sans pesée de sortie → en_cours
    $t = $service->executeTransformation([
        'product_source' => 'Poulet Entier Frais', 'type' => 'fume',
        'input_kg' => 20, 'output_kg' => 0, 'production_date' => now()->toDateString(),
    ]);
    expect($t->status)->toBe('en_cours');

    // Pesée de sortie (fin du fumage) via la route dédiée
    $this->patch(route('slaughter.transform.complete', $t), ['output_kg' => 14])
        ->assertSessionHas('success');

    $t = $t->fresh();
    expect($t->status)->toBe('termine');
    expect((float) $t->yield_percent)->toEqual(70.0);

    $smoked = FinishedProduct::where('product_type', 'fume')->first();
    expect((float) $smoked->current_quantity_kg)->toEqual(14.0);

    // Re-complétion → refus, pas de double entrée en stock
    $this->patch(route('slaughter.transform.complete', $t), ['output_kg' => 14])
        ->assertSessionHas('error');
    expect((float) $smoked->fresh()->current_quantity_kg)->toEqual(14.0);
});

test('rendement de transformation aberrant (sortie >> entrée) : refusé', function () {
    $this->actingAs($this->managerUser);

    FinishedProduct::create([
        'product_name' => 'Poulet Entier Frais', 'product_type' => 'entier_frais',
        'storage_location' => 'frais', 'unit' => 'kg', 'unit_price' => 0,
        'current_quantity_kg' => 40,
    ]);

    expect(fn () => app(SlaughterService::class)->executeTransformation([
        'product_source' => 'Poulet Entier Frais', 'type' => 'fume',
        'input_kg' => 10, 'output_kg' => 25, 'production_date' => now()->toDateString(),
    ]))->toThrow(Exception::class, 'Rendement aberrant');
});

// ─── JOURNAL PRODUITS FINIS ───

test('ajustement et élimination de produits finis : journalisés en base', function () {
    $product = FinishedProduct::create([
        'product_name' => 'Cuisses', 'product_type' => 'cuisse',
        'storage_location' => 'frais', 'unit' => 'kg', 'unit_price' => 0,
        'current_quantity_kg' => 25,
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('slaughter.finished.adjust', $product), [
            'new_quantity_kg' => 22.5, 'reason' => 'Recomptage balance',
        ])->assertSessionHas('success');

    $this->actingAs($this->managerUser)
        ->post(route('slaughter.finished.dispose', $product), [
            'reason' => 'Rupture chaîne du froid',
        ])->assertSessionHas('success');

    expect((float) $product->fresh()->current_quantity_kg)->toEqual(0.0);

    $journal = FinishedProductAdjustment::orderBy('id')->get();
    expect($journal)->toHaveCount(2);
    expect($journal[0]->type)->toBe(FinishedProductAdjustment::TYPE_ADJUSTMENT);
    expect((float) $journal[0]->old_kg)->toEqual(25.0);
    expect((float) $journal[0]->new_kg)->toEqual(22.5);
    expect($journal[1]->type)->toBe(FinishedProductAdjustment::TYPE_DISPOSAL);
    expect((float) $journal[1]->old_kg)->toEqual(22.5);
    expect((float) $journal[1]->new_kg)->toEqual(0.0);
    expect($journal[1]->reason)->toBe('Rupture chaîne du froid');
});

// ─── ANNULATION D'ORDRE ───

test('un ordre planifié s\'annule ; un ordre annulé ne s\'exécute plus', function () {
    $order = ($this->makeOrder)();

    $this->actingAs($this->managerUser)
        ->patch(route('slaughter.orders.cancel', $order))
        ->assertSessionHas('success');

    expect($order->fresh()->status)->toBe('annule');
    expect($order->fresh()->notes)->toContain('ANNULÉ');

    // L'exécution d'un ordre annulé est refusée
    $this->actingAs($this->managerUser)
        ->post(route('slaughter.execute.store', $order), $this->executePayload)
        ->assertSessionHas('error');
    expect($this->batch->fresh()->current_quantity)->toBe(100);

    // Un ordre déjà exécuté ne s'annule pas
    $order2 = ($this->makeOrder)();
    app(SlaughterService::class)->executeSlaughter($order2, $this->executePayload);
    $this->actingAs($this->managerUser)
        ->patch(route('slaughter.orders.cancel', $order2))
        ->assertSessionHas('error');
    expect($order2->fresh()->status)->toBe('termine');
});
