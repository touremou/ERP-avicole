<?php

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\ValidateSale;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    // Une session de caisse ouverte : la vente POS du 1er test en a besoin.
    CashRegisterSession::create([
        'user_id' => $this->adminUser->id, 'status' => 'open', 'opened_at' => now(), 'opening_float' => 0,
    ]);
});

function hubStock(int $qty = 100, float $price = 2000): Stock
{
    $stock = Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet entier', 'unit' => 'piece',
        'current_quantity' => $qty, 'unit_price' => $price, 'last_unit_price' => $price, 'alert_threshold' => 5,
    ]);
    \App\Models\Product::create([
        'name' => 'Poulet entier', 'product_type' => 'produits_finis', 'stock_id' => $stock->id,
        'unit' => 'piece', 'base_price' => $price, 'is_active' => true,
    ]);
    return $stock;
}

test('le hub commerce est accessible et agrège le CA du jour', function () {
    $stock = hubStock(100, 2000);

    // Vente POS comptant : 10 × 2000 = 20 000 (livrée, soldée, aujourd'hui).
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => [['product_id' => \App\Models\Product::where('stock_id', $stock->id)->value('id'), 'quantity' => 10, 'unit_price' => 2000]],
    ])->assertRedirect();

    $kpis = $this->actingAs($this->adminUser)->get(route('commerce.index'))
        ->assertOk()
        ->assertSee('Commerce')
        ->viewData('kpis');

    expect($kpis['ca_jour'])->toBe(20000.0)
        ->and($kpis['ventes_jour'])->toBe(1)
        ->and($kpis['creances'])->toBe(0.0);
});

test('le hub additionne les créances clients des ventes à crédit non soldées', function () {
    $this->actingAs($this->adminUser); // CreateSale lit Auth::id()
    $stock = hubStock(100, 2000);

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-HUB1', 'name' => 'Client crédit',
        'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);

    // Vente à crédit : total 20 000, acompte 5 000 → reste 15 000.
    $sale = (new CreateSale())->execute([
        'client_id' => $client->id, 'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'tax_rate' => 0,
        'items' => [[
            'product_type' => 'produits_finis', 'product_name' => 'Poulet entier',
            'product_id' => $stock->id, 'quantity' => 10, 'unit' => 'piece', 'unit_price' => 2000,
        ]],
        'immediate_payment' => 5000, 'payment_method' => 'especes',
    ]);
    (new ValidateSale())->execute($sale);

    $kpis = $this->get(route('commerce.index'))->assertOk()->viewData('kpis');

    expect($kpis['creances'])->toBe(15000.0);
});

test('une vente en brouillon ne compte ni dans le CA ni dans les créances', function () {
    $this->actingAs($this->adminUser);
    $stock = hubStock(100, 2000);

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-HUB2', 'name' => 'Brouillon',
        'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif', 'credit_limit' => 0, 'balance' => 0,
    ]);

    // Brouillon (non validé) → exclu des scopes validated().
    (new CreateSale())->execute([
        'client_id' => $client->id, 'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'tax_rate' => 0,
        'items' => [[
            'product_type' => 'produits_finis', 'product_name' => 'Poulet entier',
            'product_id' => $stock->id, 'quantity' => 10, 'unit' => 'piece', 'unit_price' => 2000,
        ]],
    ]);

    $kpis = $this->get(route('commerce.index'))->assertOk()->viewData('kpis');

    expect($kpis['ca_jour'])->toBe(0.0)
        ->and($kpis['ventes_jour'])->toBe(0)
        ->and($kpis['creances'])->toBe(0.0);
});
