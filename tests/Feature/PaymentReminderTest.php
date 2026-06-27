<?php

use App\Models\Client;
use App\Models\PaymentReminder;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\NotificationHub;
use App\Services\WhatsAppService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Setting::set('ventes.payment_delay_days', 30);
});

function overdueSale(array $overrides = []): Sale
{
    $farm = session('current_farm_id');
    $client = Client::create(array_merge([
        'farm_id' => $farm, 'client_id' => 'CLI-' . fake()->unique()->numerify('###'),
        'name' => 'Débiteur', 'type' => 'entreprise', 'category' => 'grossiste', 'phone' => '620111222',
    ], $overrides['client'] ?? []));

    return Sale::create(array_merge([
        'farm_id' => $farm, 'reference' => 'BL-' . fake()->unique()->numerify('#####'), 'client_id' => $client->id,
        'user_id' => \App\Models\User::value('id'), 'sale_date' => now()->subDays(45), 'type' => 'bon_livraison',
        'status' => 'valide', 'subtotal' => 10000, 'total_amount' => 10000, 'paid_amount' => 0,
        'payment_status' => 'impaye',
    ], $overrides['sale'] ?? []));
}

// ─── Détection des retards ─────────────────────────────────────────────────────

test('une vente impayée échue est détectée comme en retard', function () {
    overdueSale(); // 45 j > délai 30 j
    overdueSale(['sale' => ['sale_date' => now()->subDays(5), 'reference' => 'BL-RECENT']]); // récente, non échue

    expect(Sale::overdue()->count())->toBe(1);
});

test('une vente soldée n\'est jamais en retard', function () {
    overdueSale(['sale' => ['paid_amount' => 10000, 'payment_status' => 'solde']]);

    expect(Sale::overdue()->count())->toBe(0);
});

test('les jours de retard sont calculés depuis l\'échéance', function () {
    $sale = overdueSale(); // vendue il y a 45 j, délai 30 j → 15 j de retard
    expect($sale->days_overdue)->toBe(15);
});

// ─── Relance manuelle ──────────────────────────────────────────────────────────

test('relancer envoie un message au client et journalise la relance', function () {
    $captured = null;
    $fake = Mockery::mock(WhatsAppService::class);
    $fake->shouldReceive('send')->andReturnUsing(function ($phone, $msg) use (&$captured) {
        $captured = ['phone' => $phone, 'msg' => $msg];
        return true;
    });
    app()->instance(NotificationHub::class, new NotificationHub($fake));

    $sale = overdueSale();

    $this->actingAs($this->adminUser)
        ->post(route('sales.receivables.remind', $sale))
        ->assertRedirect();

    expect($captured['phone'])->toBe('620111222')
        ->and($captured['msg'])->toContain($sale->reference)
        ->and(PaymentReminder::where('sale_id', $sale->id)->whereNotNull('sent_at')->count())->toBe(1);
});

// ─── Commande automatique + anti-doublon ───────────────────────────────────────

test('la commande relance les ventes échues sans en relancer deux fois', function () {
    $fake = Mockery::mock(WhatsAppService::class);
    $fake->shouldReceive('send')->andReturnTrue();
    app()->instance(NotificationHub::class, new NotificationHub($fake));

    $sale = overdueSale();

    $this->artisan('sales:payment-reminders')->assertExitCode(0);
    expect(PaymentReminder::where('sale_id', $sale->id)->count())->toBe(1);

    // Relance immédiate à nouveau : cooldown → aucune nouvelle relance.
    $this->artisan('sales:payment-reminders')->assertExitCode(0);
    expect(PaymentReminder::where('sale_id', $sale->id)->count())->toBe(1);
});

// ─── Accès ──────────────────────────────────────────────────────────────────

test('la page de recouvrement liste les encours en retard', function () {
    $sale = overdueSale();

    $this->actingAs($this->adminUser)
        ->get(route('sales.receivables'))
        ->assertOk()
        ->assertSee('Recouvrement')
        ->assertSee($sale->reference);
});
