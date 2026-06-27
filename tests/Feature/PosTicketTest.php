<?php

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Stock;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    CashRegisterSession::create([
        'user_id' => $this->adminUser->id, 'status' => 'open', 'opened_at' => now(), 'opening_float' => 0,
    ]);
});

function ticketStock(): Stock
{
    return Stock::create([
        'category' => Stock::CAT_PRODUITS_FINIS, 'item_name' => 'Poulet', 'unit' => 'piece',
        'current_quantity' => 100, 'unit_price' => 2000, 'last_unit_price' => 2000, 'alert_threshold' => 5,
    ]);
}

function ticketCheckout($test, $user, Stock $stock): void
{
    $test->actingAs($user)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => [['stock_id' => $stock->id, 'quantity' => 2, 'unit_price' => 2000]],
    ]);
}

test('ticket activé (défaut) : l\'encaissement redirige vers le reçu', function () {
    $stock = ticketStock();

    $resp = $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => [['stock_id' => $stock->id, 'quantity' => 2, 'unit_price' => 2000]],
    ]);

    $sale = Sale::latest('id')->first();
    $resp->assertRedirect(route('pos.receipt', $sale))->assertSessionHas('success');
});

test('ticket désactivé : l\'encaissement reste sur le POS (aucun reçu)', function () {
    Setting::where('group', 'ventes')->where('key', 'ticket_enabled')->update(['value' => '0']);
    Setting::clearCache();

    $stock = ticketStock();

    $this->actingAs($this->adminUser)->post(route('pos.checkout'), [
        'payment_method' => 'especes',
        'items'          => [['stock_id' => $stock->id, 'quantity' => 2, 'unit_price' => 2000]],
    ])->assertRedirect(route('pos.index'))->assertSessionHas('success');

    expect(Sale::count())->toBe(1); // la vente est bien créée, seul le reçu est sauté
});

test('le message de pied de ticket est configurable', function () {
    Setting::where('group', 'ventes')->where('key', 'ticket_footer')->update(['value' => 'Bonne journée chez AviSmart']);
    Setting::clearCache();

    $stock = ticketStock();
    ticketCheckout($this, $this->adminUser, $stock);
    $sale = Sale::latest('id')->first();

    $this->actingAs($this->adminUser)->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertSee('Bonne journée chez AviSmart')
        ->assertDontSee('Merci de votre achat !');
});

test('un pied de ticket vidé n\'affiche aucune ligne de remerciement', function () {
    Setting::where('group', 'ventes')->whereIn('key', ['ticket_footer', 'ticket_note'])->update(['value' => '']);
    Setting::clearCache();

    $stock = ticketStock();
    ticketCheckout($this, $this->adminUser, $stock);
    $sale = Sale::latest('id')->first();

    $this->actingAs($this->adminUser)->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertDontSee('Merci de votre achat !')
        ->assertDontSee('Conservez ce reçu');
});
