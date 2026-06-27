<?php

use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser);

    $this->stock = Stock::create([
        'category' => Stock::CAT_CONSO, 'item_name' => 'Maïs', 'unit' => 'KG',
        'current_quantity' => 100, 'unit_price' => 0, 'last_unit_price' => 2000, 'alert_threshold' => 5,
    ]);
});

test('une démarque réduit le stock et chiffre la perte au CMP', function () {
    $this->post(route('stock-adjustments.store'), [
        'stock_id' => $this->stock->id, 'counted_quantity' => 90,
        'reason' => 'casse', 'adjustment_date' => now()->toDateString(),
    ])->assertRedirect(route('stock-adjustments.index'))->assertSessionHas('success');

    expect((float) $this->stock->fresh()->current_quantity)->toBe(90.0);

    $adj = StockAdjustment::latest('id')->first();
    expect($adj->type)->toBe('perte')
        ->and((float) $adj->delta)->toBe(-10.0)
        ->and((float) $adj->unit_cost)->toBe(2000.0)
        ->and((float) $adj->value_impact)->toBe(20000.0) // 10 × 2000
        ->and($adj->reference)->toStartWith('AJ-');

    // Flux physique tracé en parallèle.
    expect(StockMovement::where('type', 'adjustment')->where('stock_id', $this->stock->id)->count())->toBe(1);
});

test('un écart positif est un gain valorisé', function () {
    $this->post(route('stock-adjustments.store'), [
        'stock_id' => $this->stock->id, 'counted_quantity' => 115,
        'reason' => 'inventaire', 'adjustment_date' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $this->stock->fresh()->current_quantity)->toBe(115.0);

    $adj = StockAdjustment::latest('id')->first();
    expect($adj->type)->toBe('gain')
        ->and((float) $adj->delta)->toBe(15.0)
        ->and((float) $adj->value_impact)->toBe(30000.0);
});

test('aucun écart (quantité identique) est refusé et ne touche pas le stock', function () {
    $this->from(route('stock-adjustments.create'))
        ->post(route('stock-adjustments.store'), [
            'stock_id' => $this->stock->id, 'counted_quantity' => 100,
            'reason' => 'inventaire', 'adjustment_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('stock-adjustments.create'))
        ->assertSessionHasErrors('counted_quantity');

    expect((float) $this->stock->fresh()->current_quantity)->toBe(100.0)
        ->and(StockAdjustment::count())->toBe(0);
});

test('le journal agrège pertes et gains valorisés', function () {
    // Perte 20 000 (100 → 90).
    $this->post(route('stock-adjustments.store'), [
        'stock_id' => $this->stock->id, 'counted_quantity' => 90,
        'reason' => 'vol', 'adjustment_date' => now()->toDateString(),
    ]);
    // Gain 10 000 (90 → 95).
    $this->post(route('stock-adjustments.store'), [
        'stock_id' => $this->stock->id, 'counted_quantity' => 95,
        'reason' => 'inventaire', 'adjustment_date' => now()->toDateString(),
    ]);

    $stats = $this->get(route('stock-adjustments.index'))->assertOk()->viewData('stats');
    expect($stats['loss_value'])->toBe(20000.0)
        ->and($stats['gain_value'])->toBe(10000.0)
        ->and($stats['net_value'])->toBe(-10000.0)
        ->and($stats['count'])->toBe(2);
});

test('le journal de démarque s\'exporte (CSV + PDF)', function () {
    $this->post(route('stock-adjustments.store'), [
        'stock_id' => $this->stock->id, 'counted_quantity' => 90,
        'reason' => 'peremption', 'adjustment_date' => now()->toDateString(),
    ]);

    $this->get(route('stock-adjustments.csv'))->assertOk()->assertDownload();
    $this->get(route('stock-adjustments.pdf'))->assertOk()->assertDownload();
});
