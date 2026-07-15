<?php

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

/** Stock vendable (nom unique à ce fichier). */
function crStock(int $qty = 100, float $price = 2000): Stock
{
    $stock = Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet', 'unit' => 'piece',
        'current_quantity' => $qty, 'unit_price' => $price, 'last_unit_price' => $price, 'alert_threshold' => 5,
    ]);
    \App\Models\Product::create(['name' => 'Poulet', 'product_type' => 'produits_finis', 'stock_id' => $stock->id, 'unit' => 'piece', 'base_price' => $price, 'is_active' => true]);
    return $stock;
}
function crItems(Stock $stock, float $qty, float $price): array {
    return [['product_id' => \App\Models\Product::where('stock_id', $stock->id)->value('id'), 'quantity' => $qty, 'unit_price' => $price]];
}

test('sans session ouverte, le POS refuse d\'encaisser et redirige vers la caisse', function () {
    $stock = crStock(100, 2000);

    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => crItems($stock, 5, 2000),
    ])
        ->assertRedirect(route('cash-register.index'))
        ->assertSessionHas('error');

    expect(Sale::count())->toBe(0)                                  // rien créé
        ->and((float) $stock->fresh()->current_quantity)->toBe(100.0); // rien déstocké
});

test('ouvrir la caisse redirige vers le POS (on ouvre pour vendre)', function () {
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 10000])
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('success');
});

test('on ne peut pas ouvrir deux sessions de caisse simultanées', function () {
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 10000])->assertSessionHas('success');
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 10000])->assertSessionHas('error');

    expect(CashRegisterSession::count())->toBe(1);
});

test('le théorique inclut le fond + les encaissements espèces de la session', function () {
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 50000])->assertSessionHas('success');
    $session = CashRegisterSession::open()->first();

    // Vente POS espèces de 20 000 pendant la session.
    $stock = crStock(100, 2000);
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => crItems($stock, 10, 2000),
    ])->assertRedirect();

    expect($session->fresh()->expectedCash())->toBe(70000.0); // 50 000 + 20 000
});

test('clôturer avec comptage des billets calcule l\'écart (manquant)', function () {
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 50000]);
    $session = CashRegisterSession::open()->first();

    $stock = crStock(100, 2000);
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => crItems($stock, 10, 2000),
    ])->assertRedirect(); // théorique = 70 000

    // Comptage : 20000×3 + 5000×1 + 1000×3 = 68 000 → manquant 2 000.
    $this->actingAs($this->adminUser)->post(route('cash-register.close', $session), [
        'counts' => [20000 => 3, 5000 => 1, 1000 => 3],
    ])->assertSessionHas('error'); // écart ≠ 0

    $session->refresh();
    expect($session->status)->toBe('closed')
        ->and((float) $session->expected_cash)->toBe(70000.0)
        ->and((float) $session->counted_cash)->toBe(68000.0)
        ->and((float) $session->difference)->toBe(-2000.0) // manquant
        ->and($session->denominations)->toBe([20000 => 3, 5000 => 1, 1000 => 3]);
});

test('caisse juste : écart nul à la clôture', function () {
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 50000]);
    $session = CashRegisterSession::open()->first();

    // Aucune vente → théorique 50 000. Comptage 20000×2 + 10000×1 = 50 000.
    $this->actingAs($this->adminUser)->post(route('cash-register.close', $session), [
        'counts' => [20000 => 2, 10000 => 1],
    ])->assertSessionHas('success'); // caisse juste

    expect((float) $session->fresh()->difference)->toBe(0.0);
});

/** Compte Caisse de trésorerie (aligné par la clôture). */
function caisseAccount(): TreasuryAccount
{
    return TreasuryAccount::create([
        'name' => 'Caisse', 'type' => 'caisse', 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true,
    ]);
}

test('la clôture reporte le comptant en trésorerie (compte Caisse suit le physique)', function () {
    $account = caisseAccount();

    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 50000]);
    $session = CashRegisterSession::open()->first();

    $stock = crStock(100, 2000);
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => crItems($stock, 10, 2000),
    ])->assertRedirect(); // théorique = 70 000

    // Comptage exact 70 000 (20000×3 + 10000×1).
    $this->actingAs($this->adminUser)->post(route('cash-register.close', $session), [
        'counts' => [20000 => 3, 10000 => 1],
    ])->assertSessionHas('success');

    expect((float) $account->fresh()->current_balance)->toBe(70000.0)
        ->and(TreasuryTransaction::where('treasury_account_id', $account->id)->where('category', 'cloture_caisse')->count())->toBe(1);
});

test('la trésorerie suit le comptant physique même en cas d\'écart', function () {
    $account = caisseAccount();

    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 50000]);
    $session = CashRegisterSession::open()->first();

    $stock = crStock(100, 2000);
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => crItems($stock, 10, 2000),
    ])->assertRedirect(); // théorique = 70 000

    // Compté 68 000 (manquant 2 000).
    $this->actingAs($this->adminUser)->post(route('cash-register.close', $session), [
        'counts' => [20000 => 3, 5000 => 1, 1000 => 3],
    ])->assertSessionHas('error'); // écart

    // Trésorerie = comptant physique, pas le théorique.
    expect((float) $account->fresh()->current_balance)->toBe(68000.0);
});

test('sans compte caisse configuré, la clôture n\'écrit rien en trésorerie (pas de crash)', function () {
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), ['opening_float' => 10000]);
    $session = CashRegisterSession::open()->first();

    $this->actingAs($this->adminUser)->post(route('cash-register.close', $session), [
        'counts' => [10000 => 1],
    ])->assertSessionHas('success');

    expect(TreasuryTransaction::count())->toBe(0);
});

test('le POS encaisse sur le compte de caisse épinglé à la session (multi-comptes)', function () {
    $caisseA = caisseAccount();
    $caisseB = TreasuryAccount::create(['name' => 'Caisse Boutique', 'type' => 'caisse', 'current_balance' => 0, 'is_active' => true]);

    // Ouverture en épinglant la caisse B.
    $this->actingAs($this->adminUser)->post(route('cash-register.open'), [
        'opening_float' => 0, 'treasury_account_id' => $caisseB->id,
    ]);

    $stock = crStock(100, 2000);
    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => crItems($stock, 5, 2000), // 10 000 espèces
    ])->assertRedirect();

    // L'encaissement va sur la caisse de la session (B), pas la caisse par défaut (A).
    expect((float) $caisseB->fresh()->current_balance)->toBe(10000.0)
        ->and((float) $caisseA->fresh()->current_balance)->toBe(0.0);
});
