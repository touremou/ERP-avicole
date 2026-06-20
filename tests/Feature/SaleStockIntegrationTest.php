<?php

use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    $this->client = Client::create([
        'farm_id'      => $this->farm->id,
        'client_id'    => 'CLI-2026-002',
        'name'         => 'Hôtel Riviera',
        'type'         => 'particulier',
        'phone'        => '622334466',
        'credit_limit' => 0,
        'balance'      => 0,
        'status'       => 'actif',
    ]);
});

function makeBrouillonSale(Client $client, Stock $stock, float $qty, string $unit): Sale
{
    $sale = Sale::create([
        'farm_id'        => $client->farm_id,
        'client_id'      => $client->id,
        'user_id'        => 1,
        'reference'      => 'BL-2026-STOCKTEST-' . $stock->id . '-' . $unit,
        'sale_date'      => now()->toDateString(),
        'type'           => 'bon_livraison',
        'status'         => 'brouillon',
        'subtotal'       => 0,
        'tax_amount'     => 0,
        'total_amount'   => 0,
        'paid_amount'    => 0,
        'payment_status' => 'impaye',
    ]);

    SaleItem::create([
        'farm_id'      => $client->farm_id,
        'sale_id'      => $sale->id,
        'product_type' => $stock->category === Stock::CAT_LAIT ? 'lait' : 'produits_finis',
        'product_name' => $stock->item_name,
        'product_id'   => $stock->id,
        'quantity'     => $qty,
        'unit'         => $unit,
        'unit_price'   => 1000,
        'total'        => $qty * 1000,
    ]);

    return $sale->fresh('items');
}

test('valider une vente de lait décrémente le stock de lait', function () {
    $stock = Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => 'Lait',
        'category'         => Stock::CAT_LAIT,
        'current_quantity' => 100,
        'unit'             => 'Litre',
        'alert_threshold'  => 0,
    ]);

    $sale = makeBrouillonSale($this->client, $stock, 20, 'litre');

    (new ValidateSale())->execute($sale);

    expect((float) $stock->fresh()->current_quantity)->toBe(80.0)
        ->and($sale->fresh()->status)->toBe('valide');
});

test('valider une vente de produits finis décrémente le stock correspondant', function () {
    $stock = Stock::create([
        'farm_id'          => $this->farm->id,
        'item_name'        => "Poussins d'un jour",
        'category'         => Stock::CAT_PRODUITS_FINIS,
        'current_quantity' => 500,
        'unit'             => 'TETE',
        'alert_threshold'  => 0,
    ]);

    $sale = makeBrouillonSale($this->client, $stock, 50, 'tete');

    (new ValidateSale())->execute($sale);

    expect((float) $stock->fresh()->current_quantity)->toBe(450.0)
        ->and($sale->fresh()->status)->toBe('valide');
});
