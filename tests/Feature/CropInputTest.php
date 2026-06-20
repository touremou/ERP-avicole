<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Plot;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function makeCycle(int $farmId): CropCycle
{
    $plot = Plot::create([
        'farm_id' => $farmId,
        'name'    => 'Parcelle Test',
        'area_ha' => 1.0,
        'status'  => Plot::STATUS_EN_CULTURE,
    ]);

    return CropCycle::create([
        'farm_id'        => $farmId,
        'plot_id'        => $plot->id,
        'code'           => 'CY-001',
        'crop_name'      => 'Maïs',
        'area_used_ha'   => 1.0,
        'planting_date'  => now()->subMonth()->toDateString(),
        'total_revenue'  => 1_000_000,
    ]);
}

test('le coût total est calculé quand il n\'est pas fourni', function () {
    $cycle = makeCycle($this->farm->id);

    $this->actingAs($this->operatorUser)
        ->post(route('crop-cycles.inputs.store', $cycle), [
            'type'       => 'engrais',
            'name'       => 'NPK 15-15-15',
            'quantity'   => 50,
            'unit'       => 'kg',
            'unit_cost'  => 4000,
            'input_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    $input = CropInput::first();
    expect((float) $input->total_cost)->toBe(200000.0); // 50 × 4000
});

test('les intrants itémisés diminuent la marge nette du cycle', function () {
    $cycle = makeCycle($this->farm->id); // revenu 1 000 000

    $this->actingAs($this->managerUser)
        ->post(route('crop-cycles.inputs.store', $cycle), [
            'type'       => 'semence',
            'name'       => 'Semence hybride',
            'total_cost' => 300000,
            'input_date' => now()->toDateString(),
        ])
        ->assertRedirect();

    expect($cycle->fresh()->inputs_cost)->toBe(300000.0)
        ->and($cycle->fresh()->net_margin)->toBe(700000.0); // 1 000 000 - 300 000
});

test('un intrant peut alimenter le stock intrants', function () {
    $cycle = makeCycle($this->farm->id);

    $this->actingAs($this->managerUser)
        ->post(route('crop-cycles.inputs.store', $cycle), [
            'type'            => 'engrais',
            'name'            => 'Urée 46%',
            'quantity'        => 100,
            'unit'            => 'kg',
            'unit_cost'       => 5000,
            'input_date'      => now()->toDateString(),
            'synced_to_stock' => 1,
            'stock_item_name' => 'Urée 46%',
        ])
        ->assertRedirect();

    $stock = Stock::where('item_name', 'Urée 46%')->where('category', Stock::CAT_INTRANTS)->first();

    expect($stock)->not->toBeNull()
        ->and((float) $stock->current_quantity)->toBe(100.0)
        ->and(CropInput::first()->synced_to_stock)->toBeTrue();
});

test('un lecteur seul (L) ne peut pas enregistrer d\'intrant', function () {
    $cycle = makeCycle($this->farm->id);

    $this->actingAs($this->readonlyUser)
        ->post(route('crop-cycles.inputs.store', $cycle), [
            'type'       => 'autre',
            'name'       => 'X',
            'input_date' => now()->toDateString(),
        ])
        ->assertSessionHas('error');

    expect(CropInput::count())->toBe(0);
});
