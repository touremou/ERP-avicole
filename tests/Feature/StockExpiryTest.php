<?php

use App\Models\NotificationTemplate;
use App\Models\Stock;
use App\Services\NotificationHub;
use App\Services\WhatsAppService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

function makeStock(array $attrs = []): Stock
{
    return Stock::create(array_merge([
        'farm_id'          => session('current_farm_id'),
        'item_name'        => 'Vaccin Newcastle',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'Unité',
        'current_quantity' => 50,
        'alert_threshold'  => 5,
    ], $attrs));
}

// ─── Scopes & accesseurs ──────────────────────────────────────────────────────

test('un article périmé est capté par le scope expired', function () {
    makeStock(['expiry_date' => now()->subDay()]);
    makeStock(['item_name' => 'Sain', 'expiry_date' => now()->addYear()]);

    expect(Stock::expired()->count())->toBe(1);
});

test('un article périmant bientôt est capté par expiringSoon', function () {
    makeStock(['expiry_date' => now()->addDays(10)]);   // dans la fenêtre
    makeStock(['item_name' => 'Loin', 'expiry_date' => now()->addDays(90)]); // hors fenêtre

    expect(Stock::expiringSoon(30)->count())->toBe(1);
});

test('un article périmé sans stock n\'est pas signalé', function () {
    makeStock(['expiry_date' => now()->subDay(), 'current_quantity' => 0]);

    expect(Stock::expired()->count())->toBe(0);
});

test('days_until_expiry est négatif pour un article périmé', function () {
    $s = makeStock(['expiry_date' => now()->subDays(3)]);

    expect($s->days_until_expiry)->toBeLessThan(0)
        ->and($s->is_expired)->toBeTrue();
});

test('un article sans date de péremption n\'a pas d\'échéance', function () {
    $s = makeStock(['expiry_date' => null]);

    expect($s->days_until_expiry)->toBeNull()
        ->and($s->is_expired)->toBeFalse();
});

// ─── Dashboard ─────────────────────────────────────────────────────────────

test('le dashboard expose les consommables en péremption', function () {
    makeStock(['expiry_date' => now()->addDays(5)]);

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('expiringStocks', fn ($c) => $c->count() === 1);
});

// ─── Saisie via le formulaire ────────────────────────────────────────────────

test('la création d\'un stock enregistre la péremption et le n° de lot', function () {
    $this->actingAs($this->adminUser)->post(route('stocks.store'), [
        'item_name'        => 'Vaccin Gumboro',
        'category'         => Stock::CAT_CONSO,
        'unit'             => 'Unité',
        'alert_threshold'  => 5,
        'current_quantity' => 20,
        'unit_price'       => 1000,
        'expiry_date'      => now()->addMonths(6)->toDateString(),
        'lot_number'       => 'GUM-2026-08',
    ])->assertRedirect();

    $stock = Stock::where('item_name', 'Vaccin Gumboro')->first();
    expect($stock->lot_number)->toBe('GUM-2026-08')
        ->and($stock->expiry_date->toDateString())->toBe(now()->addMonths(6)->toDateString());
});

// ─── Notification WhatsApp ───────────────────────────────────────────────────

test('la commande stock:check-expiry alerte sur les articles concernés', function () {
    makeStock(['expiry_date' => now()->subDay()]);              // périmé
    makeStock(['item_name' => 'Bientôt', 'expiry_date' => now()->addDays(7)]); // bientôt
    \App\Models\Setting::set('whatsapp.admin_phone', '620000000');

    $captured = null;
    $fake = Mockery::mock(WhatsAppService::class);
    $fake->shouldReceive('send')->andReturnUsing(function ($phone, $msg) use (&$captured) {
        $captured = $msg;
        return true;
    });
    app()->instance(NotificationHub::class, new NotificationHub($fake));

    $this->artisan('stock:check-expiry')->assertExitCode(0);

    expect($captured)->toContain('PÉRIMÉ')->toContain('Vaccin Newcastle');
});
