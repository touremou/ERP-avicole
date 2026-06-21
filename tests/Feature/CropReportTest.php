<?php

use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropTransformation;
use App\Models\Plot;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function reportCycle(int $farmId): CropCycle
{
    $plot = Plot::create(['farm_id' => $farmId, 'name' => 'Parcelle R', 'area_ha' => 2, 'status' => Plot::STATUS_EN_CULTURE]);

    $cycle = CropCycle::create([
        'farm_id'       => $farmId,
        'plot_id'       => $plot->id,
        'crop_name'     => 'Maïs',
        'area_used_ha'  => 2,
        'planting_date' => now()->toDateString(),
        'status'        => CropCycle::STATUS_TERMINE,
        'total_revenue' => 500000,
    ]);
    $cycle->harvests()->create(['farm_id' => $farmId, 'harvest_date' => now()->toDateString(), 'quantity' => 1000, 'unit' => 'kg']);

    return $cycle;
}

test('le hub des rapports répond', function () {
    $this->actingAs($this->readonlyUser)->get(route('crop-reports.index'))->assertOk()->assertSee('Rendements', false);
});

test('le rapport de rendement agrège la production par culture', function () {
    reportCycle($this->farm->id);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-reports.yield', ['year' => now()->year]))
        ->assertOk()
        ->assertSee('Maïs');
});

test('le rapport des intrants répond et totalise les coûts', function () {
    $cycle = reportCycle($this->farm->id);
    CropInput::create([
        'farm_id'       => $this->farm->id,
        'crop_cycle_id' => $cycle->id,
        'type'          => 'engrais',
        'name'          => 'NPK',
        'quantity'      => 50,
        'total_cost'    => 75000,
        'input_date'    => now()->toDateString(),
    ]);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-reports.inputs', ['year' => now()->year]))
        ->assertOk()
        ->assertSee('NPK');
});

test('le rapport des campagnes répond', function () {
    CropCampaign::create([
        'farm_id'    => $this->farm->id,
        'name'       => 'Campagne 2026',
        'year'       => now()->year,
        'season'     => 'saison_seche',
        'start_date' => now()->toDateString(),
    ]);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-reports.campaigns', ['year' => now()->year]))
        ->assertOk()
        ->assertSee('Campagne 2026');
});

test('le rapport des transformations calcule le rendement moyen', function () {
    CropTransformation::create([
        'farm_id'             => $this->farm->id,
        'batch_number'        => 'TRV-RPT-1',
        'input_product'       => 'Manioc',
        'output_product'      => 'Gari',
        'transformation_type' => 'sechage',
        'input_quantity'      => 100,
        'output_quantity'     => 40,
        'yield_percent'       => 40,
        'production_date'     => now()->toDateString(),
    ]);

    $this->actingAs($this->readonlyUser)
        ->get(route('crop-reports.transformations', ['year' => now()->year]))
        ->assertOk()
        ->assertSee('Gari');
});
