<?php

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\ValidateSale;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    $this->actingAs($this->adminUser); // CreateSale lit Auth::id()

    Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet entier', 'unit' => 'piece',
        'current_quantity' => 100, 'unit_price' => 2000, 'last_unit_price' => 2000, 'alert_threshold' => 5,
    ]);

    $this->client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-REL1', 'name' => 'Client relevé',
        'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif', 'credit_limit' => 30000, 'balance' => 0,
    ]);
});

/** Vente à crédit (total 20 000, acompte 5 000 → reste 15 000). */
function creditSale(Client $client): App\Models\Sale
{
    $sale = (new CreateSale())->execute([
        'client_id' => $client->id, 'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'tax_rate' => 0,
        'items' => [[
            'product_type' => 'produits_finis', 'product_name' => 'Poulet entier',
            'product_id' => Stock::first()->id, 'quantity' => 10, 'unit' => 'piece', 'unit_price' => 2000,
        ]],
        'immediate_payment' => 5000, 'payment_method' => 'especes',
    ]);
    (new ValidateSale())->execute($sale);

    return $sale;
}

test('le relevé réconcilie au solde dû : vente (débit) + acompte (crédit)', function () {
    creditSale($this->client);

    $st = $this->get(route('clients.statement', $this->client))->assertOk()->viewData('statement');

    expect($st['total_debit'])->toBe(20000.0)
        ->and($st['total_credit'])->toBe(5000.0)
        ->and($st['balance'])->toBe(15000.0)
        ->and($st['rows'])->toHaveCount(2)
        ->and($st['rows']->first()['type'])->toBe('vente')   // la vente précède le règlement
        ->and($st['rows']->last()['balance'])->toBe(15000.0); // solde glissant final
});

test('un remboursement (avoir) est un crédit négatif qui ré-augmente le solde', function () {
    $sale = creditSale($this->client);

    Payment::create([
        'sale_id' => $sale->id, 'amount' => -3000, 'payment_date' => now()->toDateString(),
        'method' => 'especes', 'received_by' => $this->adminUser->id,
    ]);

    $st = $this->get(route('clients.statement', $this->client))->assertOk()->viewData('statement');

    // Crédit net = 5 000 − 3 000 = 2 000 ; solde = 20 000 − 2 000 = 18 000.
    expect($st['total_credit'])->toBe(2000.0)
        ->and($st['balance'])->toBe(18000.0)
        ->and($st['rows'])->toHaveCount(3);

    $refund = $st['rows']->firstWhere('type', 'remboursement');
    expect($refund['credit'])->toBe(-3000.0);
});

test('le relevé sans mouvement affiche un solde nul', function () {
    $st = $this->get(route('clients.statement', $this->client))->assertOk()->viewData('statement');

    expect($st['balance'])->toBe(0.0)
        ->and($st['rows'])->toHaveCount(0);
});

test('le relevé s\'exporte en PDF', function () {
    creditSale($this->client);

    $this->get(route('clients.statement.pdf', $this->client))
        ->assertOk()
        ->assertDownload('releve-' . $this->client->client_id . '.pdf');
});
