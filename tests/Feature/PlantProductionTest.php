<?php

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function makePlot(int $farmId): Plot
{
    return Plot::create([
        'farm_id' => $farmId,
        'name'    => 'Parcelle Nord',
        'area_ha' => 2.5,
        'status'  => Plot::STATUS_DISPONIBLE,
    ]);
}

test('le module cultures est enregistré dans la matrice RBAC', function () {
    expect(\App\Models\Module::where('slug', 'cultures')->exists())->toBeTrue();
});

test('un opérateur (C) peut créer une parcelle', function () {
    $this->actingAs($this->operatorUser)
        ->post(route('plots.store'), [
            'name'    => 'Parcelle Sud',
            'area_ha' => 1.2,
        ])
        ->assertRedirect();

    expect(Plot::where('name', 'Parcelle Sud')->exists())->toBeTrue();
});

test('un lecteur seul (L) ne peut pas créer de parcelle', function () {
    $this->actingAs($this->readonlyUser)
        ->post(route('plots.store'), ['name' => 'X', 'area_ha' => 1])
        ->assertSessionHas('error');

    expect(Plot::where('name', 'X')->exists())->toBeFalse();
});

test('démarrer un cycle de culture met la parcelle en culture', function () {
    $plot = makePlot($this->farm->id);

    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.store'), [
            'plot_id'       => $plot->id,
            'crop_name'     => 'Maïs',
            'area_used_ha'  => 2.0,
            'planting_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    $cycle = CropCycle::first();
    expect($cycle)->not->toBeNull()
        ->and($cycle->status)->toBe(CropCycle::STATUS_EN_COURS)
        ->and($plot->fresh()->status)->toBe(Plot::STATUS_EN_CULTURE);
});

test('enregistrer une récolte bascule le cycle en phase de récolte et cumule la quantité', function () {
    $plot  = makePlot($this->farm->id);
    $cycle = CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Manioc',
        'area_used_ha'  => 2.0,
        'planting_date' => now()->subMonths(3)->toDateString(),
    ]);

    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.harvests.store', $cycle), [
            'harvest_date' => now()->toDateString(),
            'quantity'     => 800,
            'unit'         => 'kg',
        ])
        ->assertRedirect();

    $cycle->refresh();
    expect($cycle->status)->toBe(CropCycle::STATUS_RECOLTE)
        ->and($cycle->total_harvested)->toBe(800.0)
        ->and($cycle->yield_per_ha)->toBe(400.0); // 800 kg / 2 ha

    // Récolte en kg sans poids net explicite → poids net déduit de la quantité.
    expect((float) $cycle->harvests()->first()->net_weight_kg)->toBe(800.0);
});

test('le poids net pesé alimente le rendement même quand la récolte est en caisses', function () {
    $plot  = makePlot($this->farm->id);
    $cycle = CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Tomate',
        'area_used_ha'  => 2.0,
        'planting_date' => now()->subMonths(2)->toDateString(),
    ]);

    // 40 caisses pesées à 600 kg : l'unité commerciale n'est pas le kg, mais le
    // poids net pesé alimente le tonnage et le rendement kg/ha.
    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.harvests.store', $cycle), [
            'harvest_date'  => now()->toDateString(),
            'quantity'      => 40,
            'unit'          => 'caisses',
            'net_weight_kg' => 600,
        ])
        ->assertRedirect();

    $cycle->refresh();
    // total_harvested et rendement reposent sur le POIDS, pas sur les caisses.
    expect($cycle->total_harvested)->toBe(600.0)
        ->and($cycle->yield_per_ha)->toBe(300.0); // 600 kg / 2 ha
});

test('une récolte en unité non-kg sans poids net ne fausse pas le rendement', function () {
    $plot  = makePlot($this->farm->id);
    $cycle = CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Salade',
        'area_used_ha'  => 1.0,
        'planting_date' => now()->subMonth()->toDateString(),
    ]);

    // 100 bottes sans pesée : le poids agronomique reste à 0 (au lieu d'agréger
    // « 100 » comme s'il s'agissait de kg) — le rendement n'est pas pollué.
    $cycle->harvests()->create([
        'farm_id'      => $this->farm->id,
        'harvest_date' => now()->toDateString(),
        'quantity'     => 100,
        'unit'         => 'bottes',
    ]);

    expect($cycle->total_harvested)->toBe(0.0)
        ->and($cycle->yield_per_ha)->toBe(0.0);
});

test('l\'écart de rendement compare le poids récolté au rendement attendu', function () {
    $plot  = makePlot($this->farm->id);
    $cycle = CropCycle::create([
        'farm_id'           => $this->farm->id,
        'plot_id'           => $plot->id,
        'crop_name'         => 'Maïs',
        'area_used_ha'      => 1.0,
        'expected_yield_kg' => 1000,
        'planting_date'     => now()->subMonths(3)->toDateString(),
    ]);

    $cycle->harvests()->create([
        'farm_id'       => $this->farm->id,
        'harvest_date'  => now()->toDateString(),
        'quantity'      => 1100,
        'unit'          => 'kg',
        'net_weight_kg' => 1100,
    ]);

    // 1100 récoltés pour 1000 attendus → +10 %.
    expect($cycle->yield_gap_percent)->toBe(10.0);

    // Sans rendement attendu, l'écart n'est pas calculable (null).
    $cycle->update(['expected_yield_kg' => 0]);
    expect($cycle->fresh()->yield_gap_percent)->toBeNull();
});

test('une récolte synchronisée alimente le stock (catégorie recoltes)', function () {
    $plot  = makePlot($this->farm->id);
    $cycle = CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Tomate',
        'area_used_ha'  => 1.0,
        'planting_date' => now()->subMonths(2)->toDateString(),
    ]);

    $this->actingAs($this->managerUser)
        ->post(route('crop-cycles.harvests.store', $cycle), [
            'harvest_date'    => now()->toDateString(),
            'quantity'        => 150,
            'unit'            => 'kg',
            'unit_price'      => 5000,
            'sync_to_stock'   => 1,
            'stock_item_name' => 'Tomate fraîche',
        ])
        ->assertRedirect();

    $stock = Stock::where('item_name', 'Tomate fraîche')
        ->where('category', Stock::CAT_RECOLTES)
        ->first();

    expect($stock)->not->toBeNull()
        ->and((float) $stock->current_quantity)->toBe(150.0)
        ->and(Harvest::where('crop_cycle_id', $cycle->id)->first()->synced_to_stock)->toBeTrue();
});

test('on ne peut pas supprimer une parcelle dont un cycle est en récolte', function () {
    $plot  = makePlot($this->farm->id);
    $plot->update(['status' => Plot::STATUS_EN_CULTURE]);
    // Cycle en phase de récolte (et non « en cours ») : la parcelle reste occupée.
    CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Igname',
        'area_used_ha'  => 2.0,
        'planting_date' => now()->subMonths(5)->toDateString(),
        'status'        => CropCycle::STATUS_RECOLTE,
    ]);

    $this->actingAs($this->adminUser)
        ->delete(route('plots.destroy', $plot))
        ->assertSessionHas('error');

    expect(Plot::whereKey($plot->id)->exists())->toBeTrue()
        ->and(CropCycle::where('plot_id', $plot->id)->exists())->toBeTrue();
});

test('clôturer un cycle libère la parcelle', function () {
    $plot  = makePlot($this->farm->id);
    $plot->update(['status' => Plot::STATUS_EN_CULTURE]);
    $cycle = CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Arachide',
        'area_used_ha'  => 2.0,
        'planting_date' => now()->subMonths(4)->toDateString(),
        'status'        => CropCycle::STATUS_RECOLTE,
    ]);

    $this->actingAs($this->managerUser)
        ->put(route('crop-cycles.update', $cycle), [
            'crop_name'     => 'Arachide',
            'area_used_ha'  => 2.0,
            'planting_date' => now()->subMonths(4)->toDateString(),
            'status'        => CropCycle::STATUS_TERMINE,
        ])
        ->assertRedirect();

    expect($cycle->fresh()->status)->toBe(CropCycle::STATUS_TERMINE)
        ->and($plot->fresh()->status)->toBe(Plot::STATUS_DISPONIBLE)
        ->and($cycle->fresh()->closing_date)->not->toBeNull();
});

test('deux cultures peuvent cohabiter sur une même parcelle si leurs surfaces se complètent', function () {
    $plot = makePlot($this->farm->id); // area_ha = 2.5

    // Premier cycle : 1.5 ha
    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.store'), [
            'plot_id'       => $plot->id,
            'crop_name'     => 'Maïs',
            'area_used_ha'  => 1.5,
            'planting_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    expect(CropCycle::where('plot_id', $plot->id)->count())->toBe(1);

    // Deuxième cycle : 1.0 ha (total = 2.5 ha = surface parcelle)
    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.store'), [
            'plot_id'       => $plot->id,
            'crop_name'     => 'Fonio',
            'area_used_ha'  => 1.0,
            'planting_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    expect(CropCycle::where('plot_id', $plot->id)->count())->toBe(2);
});

test('on ne peut pas dépasser la surface totale de la parcelle', function () {
    $plot = makePlot($this->farm->id); // area_ha = 2.5

    // Cycle existant : 2.0 ha
    CropCycle::create([
        'farm_id'       => $this->farm->id,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Maïs',
        'area_used_ha'  => 2.0,
        'planting_date' => now()->toDateString(),
    ]);
    $plot->update(['status' => Plot::STATUS_EN_CULTURE]);

    // Tentative d'ajout de 1.0 ha → total 3.0 ha > 2.5 ha
    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.store'), [
            'plot_id'       => $plot->id,
            'crop_name'     => 'Fonio',
            'area_used_ha'  => 1.0,
            'planting_date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors(['area_used_ha']);

    expect(CropCycle::where('plot_id', $plot->id)->count())->toBe(1);
});
