<?php

use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();

    // Toute vente POS passe par une session de caisse ouverte.
    CashRegisterSession::create([
        'user_id' => $this->adminUser->id, 'status' => 'open', 'opened_at' => now(), 'opening_float' => 0,
    ]);
});

function sellableStock(int $qty = 100, float $price = 2000): Stock
{
    // farm_id auto-rempli depuis la session par le trait BelongsToFarm.
    $stock = Stock::create([
        'category'        => Stock::CAT_PRODUITS_FINIS,
        'item_name'       => 'Poulet entier',
        'unit'            => 'piece',
        'current_quantity'=> $qty,
        'unit_price'      => $price,
        'last_unit_price' => $price,
        'alert_threshold' => 5,
    ]);

    // Le POS s'appuie sur le CATALOGUE : on rattache un article au stock.
    \App\Models\Product::create([
        'name' => 'Poulet entier', 'product_type' => 'produits_finis',
        'stock_id' => $stock->id, 'unit' => 'piece', 'base_price' => $price, 'is_active' => true,
    ]);

    return $stock;
}

/** Construit les lignes POS (catalogue) pour un stock donné. */
function posItems(Stock $stock, float $qty, float $price): array
{
    $productId = \App\Models\Product::where('stock_id', $stock->id)->value('id');
    return [['product_id' => $productId, 'quantity' => $qty, 'unit_price' => $price]];
}

test('le POS encaisse une vente complète (validée, livrée, soldée) et déstocke', function () {
    $stock = sellableStock(100, 2000);

    $this->actingAs($this->adminUser)
        ->post(route('pos.checkout'), [
            'payment_method' => 'especes',
            'items'          => posItems($stock, 10, 2000),
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

test('le POS redirige vers le reçu, qui s\'affiche', function () {
    $stock = sellableStock(50, 2000);

    $resp = $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => posItems($stock, 3, 2000),
    ]);

    $sale = Sale::latest('id')->first();
    $resp->assertRedirect(route('pos.receipt', $sale)); // → ticket de caisse

    $this->actingAs($this->adminUser)->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertSee($sale->reference)
        ->assertSee('6 000') // total 3 × 2000
        ->assertSee('Merci de votre achat !');
});

test('le POS rejette une quantité supérieure au stock (rien n\'est créé ni déstocké)', function () {
    $stock = sellableStock(5, 2000);

    $this->actingAs($this->adminUser)
        ->post(route('pos.checkout'), [
            'payment_method' => 'especes',
            'items'          => posItems($stock, 10, 2000),
        ])
        ->assertSessionHas('error');

    expect(Sale::count())->toBe(0)
        ->and((float) $stock->fresh()->current_quantity)->toBe(5.0); // intact
});

test('le Z de caisse récapitule encaissements et remboursements du jour par mode', function () {
    $stock = sellableStock(100, 2000);

    // Vente POS : 5 × 2000 = 10 000 (espèces).
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => posItems($stock, 5, 2000),
    ])->assertRedirect();
    $sale = Sale::latest('id')->first();

    // Retour de 2 → remboursement 4 000 (espèces, paiement négatif).
    $this->actingAs($this->adminUser)->post(route('sales.return.store', $sale), [
        'refund_method' => 'especes',
        'returns'       => [$sale->items->first()->id => 2],
    ])->assertRedirect();

    $report = $this->actingAs($this->adminUser)->get(route('pos.report'))->assertOk()->viewData('report');

    $especes = collect($report['rows'])->firstWhere('label', 'Espèces');
    expect((float) $especes['in'])->toBe(10000.0)   // encaissé
        ->and((float) $especes['out'])->toBe(4000.0) // remboursé
        ->and((float) $especes['net'])->toBe(6000.0)
        ->and((float) $report['total_net'])->toBe(6000.0)
        ->and($report['tickets_count'])->toBe(1)
        ->and($report['refunds_count'])->toBe(1);
});

test('l\'encaissement express solde une vente à crédit et redirige vers le ticket', function () {
    $this->actingAs($this->adminUser); // CreateSale lit Auth::id()
    $stock = sellableStock(100, 2000);

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-0007', 'name' => 'Client crédit',
        'type' => 'particulier', 'category' => 'detaillant', 'status' => 'actif',
        'credit_limit' => 0, 'balance' => 0,
    ]);

    // Vente à crédit : total 20 000, acompte 5 000 → reste 15 000.
    $sale = (new \App\Actions\Sale\CreateSale())->execute([
        'client_id' => $client->id, 'sale_date' => now()->toDateString(), 'type' => 'bon_livraison', 'tax_rate' => 0,
        'items' => [[
            'product_type' => 'produits_finis', 'product_name' => 'Poulet entier',
            'product_id' => $stock->id, 'quantity' => 10, 'unit' => 'piece', 'unit_price' => 2000,
        ]],
        'immediate_payment' => 5000, 'payment_method' => 'especes',
    ]);
    (new \App\Actions\Sale\ValidateSale())->execute($sale);
    $sale->refresh();
    expect($sale->payment_status)->toBe('partiel')
        ->and((float) $sale->remaining_amount)->toBe(15000.0);

    // Encaissement express du solde → reçu.
    $this->post(route('pos.encash', $sale), ['method' => 'especes'])
        ->assertRedirect(route('pos.receipt', $sale))
        ->assertSessionHas('success');

    $sale->refresh();
    expect($sale->payment_status)->toBe('solde')
        ->and((float) $sale->paid_amount)->toBe(20000.0)
        ->and((float) $sale->remaining_amount)->toBe(0.0);
});

test('un article lié à un stock à 0 reste visible au POS (rupture, non vendable)', function () {
    $stock = Stock::create([
        'category' => Stock::CAT_OEUFS, 'item_name' => 'Œuf L', 'unit' => 'alveole',
        'current_quantity' => 0, 'alert_threshold' => 5,
    ]);
    \App\Models\Product::create([
        'name' => 'Œuf L', 'product_type' => 'oeufs', 'stock_id' => $stock->id,
        'unit' => 'alveole', 'base_price' => 3000, 'is_active' => true,
    ]);

    $resp = $this->actingAs($this->adminUser)->get(route('pos.index'))->assertOk();
    $card = collect($resp->viewData('products'))->firstWhere('name', 'Œuf L');

    // Présent dans la grille (plus caché) mais quantité 0 → grisé/non vendable côté UI.
    expect($card)->not->toBeNull()->and($card['qty'])->toBe(0.0);
});

test('un article du catalogue NON lié au stock est vendable au POS (vente libre)', function () {
    // Article catalogue sans stock lié (ex. « Œufs XL » non suivi en stock).
    $product = \App\Models\Product::create([
        'name' => 'Œufs XL', 'product_type' => 'oeufs', 'unit' => 'alveole',
        'base_price' => 1150, 'is_active' => true, // stock_id null
    ]);

    // Il apparaît dans la grille POS avec qty null (non suivi).
    $resp = $this->actingAs($this->adminUser)->get(route('pos.index'))->assertOk();
    $card = collect($resp->viewData('products'))->firstWhere('name', 'Œufs XL');
    expect($card)->not->toBeNull()->and($card['qty'])->toBeNull();

    // Et il s'encaisse sans contrôle de stock ni erreur.
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 1150]],
    ])->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect((float) $sale->total_amount)->toBe(4600.0)
        ->and($sale->items->first()->product_ref_id)->toBe($product->id)
        ->and($sale->items->first()->product_id)->toBeNull(); // pas de stock cible
});

test('le POS déstocke un article de LITIÈRE lié au stock (catégorie hors STOCK_TYPES)', function () {
    // Copeaux (litière) : catégorie 'litieres', PAS dans SaleItem::STOCK_TYPES.
    // Le déstockage doit néanmoins se produire car l'article catalogue est LIÉ
    // à un stock (product_id), et syncMovement doit cibler la catégorie RÉELLE
    // du stock résolu — pas celle dérivée du product_type.
    $stock = Stock::create([
        'category'        => Stock::CAT_LITIERES,
        'item_name'       => 'Copeaux de bois',
        'unit'            => 'sac',
        'current_quantity'=> 40,
        'unit_price'      => 1500,
        'last_unit_price' => 1500,
        'alert_threshold' => 5,
    ]);

    $product = \App\Models\Product::create([
        'name' => 'Copeaux de bois', 'product_type' => 'materiel',
        'stock_id' => $stock->id, 'unit' => 'sac', 'base_price' => 1500, 'is_active' => true,
    ]);

    $this->actingAs($this->adminUser)
        ->post(route('pos.checkout'), [
            'payment_method' => 'especes',
            'items'          => [['product_id' => $product->id, 'quantity' => 6, 'unit_price' => 1500]],
        ])
        ->assertRedirect();

    expect((float) $stock->fresh()->current_quantity)->toBe(34.0); // 40 − 6 déstocké
});

test('une vente POS est de type comptant et porte une référence ticket (TKT-…)', function () {
    $stock = sellableStock(50, 2000);

    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => posItems($stock, 2, 2000),
    ])->assertRedirect();

    $sale = Sale::latest('id')->first();
    expect($sale->type)->toBe('comptant')                          // ni BL ni facture
        ->and($sale->reference)->toStartWith('TKT-')               // ticket de caisse
        ->and($sale->reference)->not->toStartWith('BL-');
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
            'items'     => posItems($stock, 3, 2000),
        ])
        ->assertRedirect();

    expect(Sale::latest('id')->first()->client_id)->toBe($client->id);
});
