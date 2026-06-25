<?php

use App\Models\Client;
use App\Models\Sale;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function sellableStock(int $qty = 100, float $price = 2000): Stock
{
    // farm_id auto-rempli depuis la session par le trait BelongsToFarm.
    return Stock::create([
        'category'        => Stock::CAT_PRODUITS_FINIS,
        'item_name'       => 'Poulet entier',
        'unit'            => 'piece',
        'current_quantity'=> $qty,
        'unit_price'      => $price,
        'last_unit_price' => $price,
        'alert_threshold' => 5,
    ]);
}

test('le POS encaisse une vente complète (validée, livrée, soldée) et déstocke', function () {
    $stock = sellableStock(100, 2000);

    $this->actingAs($this->adminUser)
        ->post(route('pos.checkout'), [
            'payment_method' => 'especes',
            'items'          => [['stock_id' => $stock->id, 'quantity' => 10, 'unit_price' => 2000]],
        ])
        ->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect($sale)->not->toBeNull()
        ->and($sale->status)->toBe('livre')           // livré immédiatement
        ->and($sale->payment_status)->toBe('solde')   // payé intégralement
        ->and((float) $sale->total_amount)->toBe(20000.0)
        ->and($sale->client->name)->toBe('Vente comptoir'); // client comptoir par défaut

    expect((float) $stock->fresh()->current_quantity)->toBe(90.0); // déstocké
    expect((float) $sale->payments->sum('amount'))->toBe(20000.0);
});

test('le POS rejette une quantité supérieure au stock (rien n\'est créé ni déstocké)', function () {
    $stock = sellableStock(5, 2000);

    $this->actingAs($this->adminUser)
        ->post(route('pos.checkout'), [
            'payment_method' => 'especes',
            'items'          => [['stock_id' => $stock->id, 'quantity' => 10, 'unit_price' => 2000]],
        ])
        ->assertSessionHas('error');

    expect(Sale::count())->toBe(0)
        ->and((float) $stock->fresh()->current_quantity)->toBe(5.0); // intact
});

test('le POS accepte un client sélectionné', function () {
    $stock = sellableStock(50, 2000);
    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-0009', 'name' => 'Hôtel Kindia',
        'type' => 'entreprise', 'category' => 'hotel_restaurant', 'status' => 'actif',
        'credit_limit' => 0, 'balance' => 0,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('pos.checkout'), [
            'client_id' => $client->id, 'payment_method' => 'orange_money',
            'items'     => [['stock_id' => $stock->id, 'quantity' => 3, 'unit_price' => 2000]],
        ])
        ->assertRedirect();

    expect(Sale::latest('id')->first()->client_id)->toBe($client->id);
});
