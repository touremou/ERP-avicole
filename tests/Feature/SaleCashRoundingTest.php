<?php

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function roundingSale(float $lineTotal): Sale
{
    $client = \App\Models\Client::create([
        'farm_id' => session('current_farm_id'), 'client_id' => 'CLI-' . fake()->unique()->numerify('###'),
        'name' => 'Client', 'type' => 'particulier', 'category' => 'detaillant',
    ]);
    $sale = Sale::create([
        'farm_id' => session('current_farm_id'), 'reference' => 'BL-' . fake()->unique()->numerify('#####'),
        'client_id' => $client->id, 'user_id' => \App\Models\User::value('id'), 'sale_date' => now(),
        'type' => 'bon_livraison', 'status' => 'brouillon', 'tax_rate' => 0,
    ]);
    SaleItem::create([
        'farm_id' => session('current_farm_id'), 'sale_id' => $sale->id, 'product_type' => 'oeufs',
        'product_name' => 'Œufs', 'quantity' => 1, 'unit' => 'alveole', 'unit_price' => $lineTotal, 'total' => $lineTotal,
    ]);
    return $sale;
}

test('sans arrondi paramétré (0), le total reste exact', function () {
    $sale = roundingSale(55100);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->total_amount)->toBe(55100.0)
        ->and((float) $sale->rounding_adjustment)->toBe(0.0);
});

test('le helper cash_round arrondit à la coupure la plus proche', function () {
    expect(cash_round(55100, 1000))->toBe(55000.0)  // vers le bas
        ->and(cash_round(55600, 1000))->toBe(56000.0) // vers le haut
        ->and(cash_round(55100, 2000))->toBe(56000.0) // coupure de 2000
        ->and(cash_round(55100, 0))->toBe(55100.0);   // désactivé
});

test('avec arrondi 1000, un total de 55100 devient 55000 payable', function () {
    Setting::set('ventes.cash_rounding', '1000');

    $sale = roundingSale(55100);
    $sale->recalculateTotals();
    $sale->refresh();

    // Le total payable est arrondi : plus de dette fantôme à l'encaissement.
    expect((float) $sale->total_amount)->toBe(55000.0)
        ->and((float) $sale->rounding_adjustment)->toBe(-100.0);

    // Un paiement de 55000 solde intégralement la vente (aucun écart).
    \App\Models\Payment::create([
        'sale_id' => $sale->id, 'amount' => 55000, 'payment_date' => now()->toDateString(),
        'method' => 'especes', 'received_by' => \App\Models\User::value('id'),
    ]);
    $sale->refreshPaymentStatus();

    expect($sale->fresh()->payment_status)->toBe('solde')
        ->and((float) $sale->fresh()->remaining_amount)->toBe(0.0);
});

test('avec arrondi 1000, un total de 55600 monte à 56000', function () {
    Setting::set('ventes.cash_rounding', '1000');

    $sale = roundingSale(55600);
    $sale->recalculateTotals();
    $sale->refresh();

    expect((float) $sale->total_amount)->toBe(56000.0)
        ->and((float) $sale->rounding_adjustment)->toBe(400.0);
});
