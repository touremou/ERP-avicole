<?php

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    // paidSale() encaisse via le POS → une session de caisse doit être ouverte.
    CashRegisterSession::create([
        'user_id' => $this->adminUser->id, 'status' => 'open', 'opened_at' => now(), 'opening_float' => 0,
    ]);
});

/** Stock vendable (nom unique à ce fichier pour éviter la redéclaration Pest). */
function stockForReturn(int $qty = 100, float $price = 2000): Stock
{
    $stock = Stock::create([
        'category'        => Stock::CAT_PRODUITS_FINIS,
        'item_name'       => 'Poulet entier',
        'unit'            => 'piece',
        'current_quantity'=> $qty,
        'unit_price'      => $price,
        'last_unit_price' => $price,
        'alert_threshold' => 5,
    ]);
    \App\Models\Product::create([
        'name' => 'Poulet entier', 'product_type' => 'produits_finis', 'stock_id' => $stock->id,
        'unit' => 'piece', 'base_price' => $price, 'is_active' => true,
    ]);
    return $stock;
}

/** Crée une vente payée+livrée de $sold unités via le POS, renvoie [sale, stock]. */
function paidSale(int $stockQty, int $sold, float $price, $user): array
{
    $stock = stockForReturn($stockQty, $price);
    test()->actingAs($user)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => [['product_id' => \App\Models\Product::where('stock_id', $stock->id)->value('id'), 'quantity' => $sold, 'unit_price' => $price]],
    ])->assertRedirect();

    return [Sale::latest('id')->first(), $stock];
}

test('un retour TOTAL restocke et rembourse intégralement', function () {
    [$sale, $stock] = paidSale(100, 10, 2000, $this->adminUser); // vendu 10 → stock 90, payé 20 000

    $item = $sale->items->first();
    $this->actingAs($this->adminUser)
        ->post(route('sales.return.store', $sale), [
            'refund_method' => 'especes',
            'returns'       => [$item->id => 10],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $sale->refresh();
    expect((float) $sale->total_amount)->toBe(0.0)
        ->and((float) $sale->paid_amount)->toBe(0.0)         // intégralement remboursé
        ->and((float) $stock->fresh()->current_quantity)->toBe(100.0); // entièrement restocké

    $return = SaleReturn::where('sale_id', $sale->id)->first();
    expect($return)->not->toBeNull()
        ->and((float) $return->total_refund)->toBe(20000.0)
        ->and($return->items)->toHaveCount(1);
});

test('un retour PARTIEL réduit la vente et rembourse au prorata', function () {
    [$sale, $stock] = paidSale(100, 10, 2000, $this->adminUser); // vendu 10 → stock 90, payé 20 000

    $item = $sale->items->first();
    $this->actingAs($this->adminUser)
        ->post(route('sales.return.store', $sale), [
            'refund_method' => 'especes',
            'returns'       => [$item->id => 4], // retour de 4
        ])
        ->assertRedirect();

    $sale->refresh();
    expect((float) $sale->total_amount)->toBe(12000.0)        // 6 restants × 2000
        ->and((float) $sale->paid_amount)->toBe(12000.0)      // 20000 − 8000 remboursés
        ->and($sale->payment_status)->toBe('solde')
        ->and((float) $sale->items->first()->quantity)->toBe(6.0)
        ->and((float) $stock->fresh()->current_quantity)->toBe(94.0); // 90 + 4 restockés

    expect((float) SaleReturn::where('sale_id', $sale->id)->first()->total_refund)->toBe(8000.0);
});

test('le journal des avoirs liste les retours et s\'exporte (CSV + PDF)', function () {
    [$sale] = paidSale(100, 10, 2000, $this->adminUser);
    $this->actingAs($this->adminUser)->post(route('sales.return.store', $sale), [
        'refund_method' => 'especes', 'returns' => [$sale->items->first()->id => 3],
    ])->assertRedirect();

    // Liste
    $this->actingAs($this->adminUser)->get(route('returns.index'))
        ->assertOk()
        ->assertSee('RET-00001')
        ->assertSee('Total remboursé');

    // CSV
    $csv = $this->actingAs($this->adminUser)->get(route('returns.csv'))->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');
    expect($csv->streamedContent())->toContain('RET-00001')->toContain('Remboursement');

    // PDF
    $pdf = $this->actingAs($this->adminUser)->get(route('returns.pdf'))->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('le formulaire de retour est refusé pour une vente non validée', function () {
    $this->actingAs($this->adminUser); // CreateSale lit Auth::id() pour user_id

    // Vente brouillon (créée mais non validée) → pas de retour possible.
    $stock = stockForReturn(50, 2000);
    $sale = (new \App\Actions\Sale\CreateSale())->execute([
        'client_id'  => \App\Models\Client::create([
            'farm_id' => $this->farm->id, 'client_id' => 'CLI-0001', 'name' => 'Test',
            'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif',
            'credit_limit' => 0, 'balance' => 0,
        ])->id,
        'sale_date'  => now()->toDateString(),
        'type'       => 'bon_livraison',
        'items'      => [[
            'product_type' => 'produits_finis', 'product_name' => 'Poulet entier',
            'product_id' => $stock->id, 'quantity' => 5, 'unit' => 'piece', 'unit_price' => 2000,
        ]],
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('sales.return.create', $sale))
        ->assertRedirect(); // refusé → redirigé avec erreur

    expect(SaleReturn::count())->toBe(0);
});
