<?php

use App\Models\Batch;
use App\Models\SlaughterOrder;
use App\Models\Transformation;
use App\Services\SlaughterService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * Lot 1 (refonte désassemblage) — hygiène du cycle d'abattage :
 *  - un ordre exécuté n'est plus re-sélectionnable (formulaire verrouillé) ;
 *  - le dashboard matérialise l'étape suivante (Découper / Clôturer) ;
 *  - une transformation peut être rattachée à son ordre d'origine (cascade).
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->batch = Batch::factory()->create([
        'code' => 'CHAIR-HYG', 'initial_quantity' => 100, 'current_quantity' => 100, 'qty_alive' => 100,
    ]);
    $this->order = SlaughterOrder::create([
        'order_number' => SlaughterOrder::generateNumber(), 'batch_id' => $this->batch->id,
        'planned_date' => now()->toDateString(), 'planned_quantity' => 30,
        'status' => 'planifie', 'requested_by' => $this->managerUser->id,
    ]);
    $this->actingAs($this->managerUser);
});

function executeHygOrder($test): void
{
    app(SlaughterService::class)->executeSlaughter($test->order, [
        'actual_quantity' => 30, 'total_live_weight_kg' => 60,
        'total_carcass_weight_kg' => 45, 'execution_date' => now()->toDateString(),
    ]);
    $test->order->refresh();
}

test("le formulaire d'exécution reste accessible pour un ordre planifié", function () {
    $this->get(route('slaughter.execute.form', $this->order))->assertOk();
});

test("un ordre terminé n'est plus ré-exécutable : formulaire verrouillé, redirection", function () {
    executeHygOrder($this);

    $this->get(route('slaughter.execute.form', $this->order))
        ->assertRedirect(route('slaughter.dashboard'))
        ->assertSessionHas('error');
});

test('un ordre annulé non plus', function () {
    $this->order->update(['status' => 'annule']);

    $this->get(route('slaughter.execute.form', $this->order))
        ->assertRedirect(route('slaughter.dashboard'));
});

test("le dashboard propose l'étape suivante du cycle : Découper puis Clôturer", function () {
    executeHygOrder($this);

    $this->get(route('slaughter.dashboard'))
        ->assertOk()
        ->assertSee('Découper', false)
        ->assertSee('Clôturer', false);
});

test('une fois le cycle clos, le dashboard affiche « Clôturé » et plus « Clôturer »', function () {
    executeHygOrder($this);
    app(\App\Actions\Slaughter\CloseSlaughterCycle::class)->execute($this->order, [
        'waste_evacuated' => true, 'zone_cleaned' => true, 'marche_avant' => true,
    ]);

    $this->get(route('slaughter.dashboard'))
        ->assertOk()
        ->assertSee('Clôturé', false);
});

test("une transformation rattachée à l'ordre apparaît au dossier de lot (cascade)", function () {
    executeHygOrder($this);

    // Transformation du stock carcasse produit par l'exécution (45 kg entiers).
    $carcassName = \App\Models\FinishedProduct::where('product_type', 'entier_frais')->value('product_name');
    $this->post(route('slaughter.transform.store'), [
        'product_source'     => $carcassName,
        'slaughter_order_id' => $this->order->id,
        'type'               => 'fume',
        'input_kg'           => 10,
        'output_kg'          => 7,
        'production_date'    => now()->toDateString(),
    ])->assertRedirect(route('slaughter.dashboard'))->assertSessionHas('success');

    $tf = Transformation::latest('id')->first();
    expect($tf->slaughter_order_id)->toBe($this->order->id)
        ->and($this->order->transformations()->count())->toBe(1);

    $this->get(route('slaughter.orders.traceability', $this->order))
        ->assertOk()
        ->assertSee('Transformations rattachées', false)
        ->assertSee($tf->batch_number, false);
});

test('sans rattachement, la transformation reste valide (slaughter_order_id null)', function () {
    executeHygOrder($this);

    $this->post(route('slaughter.transform.store'), [
        'product_source'  => \App\Models\FinishedProduct::where('product_type', 'entier_frais')->value('product_name'),
        'type'            => 'fume',
        'input_kg'        => 5,
        'output_kg'       => 4,
        'production_date' => now()->toDateString(),
    ])->assertRedirect(route('slaughter.dashboard'));

    expect(Transformation::latest('id')->first()->slaughter_order_id)->toBeNull();
});

/*
 * Verrou post-clôture : un cycle clôturé (checklist HACCP signée) ne reçoit
 * plus AUCUNE activité — ni découpe, ni rattachement de transformation.
 */

function closeHygOrder($test): void
{
    app(\App\Actions\Slaughter\CloseSlaughterCycle::class)->execute($test->order, [
        'waste_evacuated' => true, 'zone_cleaned' => true, 'marche_avant' => true,
    ]);
    $test->order->refresh();
}

test('cycle clôturé : le formulaire de découpe est verrouillé', function () {
    executeHygOrder($this);
    closeHygOrder($this);

    $this->get(route('slaughter.cutting.form', $this->order))
        ->assertRedirect(route('slaughter.dashboard'))
        ->assertSessionHas('error');
});

test('cycle clôturé : la découpe est refusée à la soumission (service)', function () {
    executeHygOrder($this);
    closeHygOrder($this);

    $this->post(route('slaughter.cutting.store', $this->order), [
        'total_input_kg' => 10, 'session_date' => now()->toDateString(),
        'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 8, 'destination' => 'stock_frais']],
    ])->assertSessionHas('error');

    expect($this->order->cuttingSessions()->count())->toBe(0);
});

test('cycle clôturé : le dashboard ne propose plus « Découper »', function () {
    executeHygOrder($this);
    closeHygOrder($this);

    $this->get(route('slaughter.dashboard'))->assertOk()->assertDontSee('Découper', false);
});

test('cycle clôturé : plus de rattachement de transformation possible', function () {
    executeHygOrder($this);
    closeHygOrder($this);

    // La liste du formulaire exclut l'ordre clos…
    $this->get(route('slaughter.transform.form'))->assertOk()->assertDontSee($this->order->order_number, false);

    // …et la soumission forcée est refusée (défense en profondeur).
    $carcassName = \App\Models\FinishedProduct::where('product_type', 'entier_frais')->value('product_name');
    $this->post(route('slaughter.transform.store'), [
        'product_source'     => $carcassName,
        'slaughter_order_id' => $this->order->id,
        'type'               => 'fume',
        'input_kg'           => 5,
        'production_date'    => now()->toDateString(),
    ])->assertSessionHasErrors('slaughter_order_id');
});

test('cycle clôturé : le formulaire de clôture redirige vers le dossier', function () {
    executeHygOrder($this);
    closeHygOrder($this);

    $this->get(route('slaughter.closure.form', $this->order))
        ->assertRedirect(route('slaughter.orders.traceability', $this->order));
});

test('sync : slaughter.cutting sur cycle clôturé → conflict (bac À corriger)', function () {
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    executeHygOrder($this);
    closeHygOrder($this);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);

    $res = $this->postJson('/api/v1/sync/push', ['operations' => [[
        'op_uuid' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'slaughter.cutting',
        'payload' => ['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'slaughter_order_id' => $this->order->id, 'session_date' => now()->toDateString(),
            'total_input_kg' => 10,
            'products' => [['type' => 'cuisse', 'name' => 'Cuisses', 'kg' => 8, 'destination' => 'stock_frais']]],
    ]]])->assertOk()->json('results.0');

    expect($res['status'])->toBe('conflict')
        ->and($this->order->cuttingSessions()->count())->toBe(0);
});

test('la synchro mobile expose closed_at sur les ordres (filtrage des listes)', function () {
    \Illuminate\Support\Facades\DB::table('farm_user')->insert([
        'farm_id' => $this->farm->id, 'user_id' => $this->managerUser->id,
        'is_default' => true, 'is_owner' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    executeHygOrder($this);
    closeHygOrder($this);
    \Laravel\Sanctum\Sanctum::actingAs($this->managerUser);

    $orders = $this->getJson('/api/v1/sync/pull')->assertOk()->json('entities.slaughter_orders.upserts');
    $mine = collect($orders)->firstWhere('id', $this->order->id);
    expect($mine)->not->toBeNull()
        ->and($mine['closed_at'])->not->toBeNull();
});
