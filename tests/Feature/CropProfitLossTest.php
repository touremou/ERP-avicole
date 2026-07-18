<?php

use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\Plot;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function closedCycle(int $farmId, float $revenue, ?string $closingDate): CropCycle
{
    $plot = Plot::create(['farm_id' => $farmId, 'name' => 'P', 'area_ha' => 1, 'status' => Plot::STATUS_DISPONIBLE]);

    return CropCycle::create([
        'farm_id'                => $farmId,
        'plot_id'                => $plot->id,
        'crop_name'              => 'Tomate',
        'area_used_ha'           => 1,
        'planting_date'          => now()->subDays(120)->toDateString(),
        'closing_date'           => $closingDate,
        'status'                 => $closingDate ? CropCycle::STATUS_TERMINE : CropCycle::STATUS_EN_COURS,
        'total_acquisition_cost' => 200000,
        'additional_costs'       => 100000,
        'total_revenue'          => $revenue,
    ]);
}

test('le compte de résultat inclut les revenus et coûts des cycles clôturés sur la période', function () {
    $cycle = closedCycle($this->farm->id, 1_500_000, now()->subDays(5)->toDateString());
    CropInput::create([
        'farm_id'       => $this->farm->id,
        'crop_cycle_id' => $cycle->id,
        'type'          => 'engrais',
        'name'          => 'NPK',
        'total_cost'    => 150000,
        'input_date'    => now()->subDays(60)->toDateString(),
    ]);

    $response = $this->actingAs($this->adminUser)->get(route('reports.profit_loss'));

    $response->assertOk()
        ->assertSee('Production végétale')
        ->assertSee('Marge directe par culture')
        ->assertSee('Tomate');
});

test('un cycle non clôturé n\'est pas compté dans le compte de résultat', function () {
    closedCycle($this->farm->id, 9_999_999, null); // en cours, pas de closing_date

    $response = $this->actingAs($this->adminUser)->get(route('reports.profit_loss'));

    $response->assertOk()
        ->assertDontSee('Marge directe par culture');
});

test('le compte de résultat expose le regroupement SYSCOHADA (classes 6 et 7)', function () {
    // Un cycle clôturé génère des produits (→ classe 7) et des coûts (→ classe 6).
    closedCycle($this->farm->id, 1_500_000, now()->subDays(5)->toDateString());

    $data = $this->actingAs($this->adminUser)->get(route('reports.profit_loss'))
        ->assertOk()
        ->assertSee('Regroupement SYSCOHADA')
        ->assertSee('Classe 7 — Produits', false)
        ->assertSee('Classe 6 — Charges', false)
        ->viewData('syscohadaCharges');

    // La production végétale (coûts) doit apparaître dans la classe 60 (Achats).
    expect(collect($data)->pluck('class'))->toContain('60');
});
