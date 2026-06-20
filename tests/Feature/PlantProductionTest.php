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
        ->assertRedirect(route('plots.index'));

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
            'crop_name' => 'Arachide',
            'status'    => CropCycle::STATUS_TERMINE,
        ])
        ->assertRedirect();

    expect($cycle->fresh()->status)->toBe(CropCycle::STATUS_TERMINE)
        ->and($plot->fresh()->status)->toBe(Plot::STATUS_DISPONIBLE)
        ->and($cycle->fresh()->closing_date)->not->toBeNull();
});
