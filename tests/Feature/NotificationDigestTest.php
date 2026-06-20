<?php

use App\Models\Client;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\NotificationHub;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Setting::set('whatsapp.driver', 'log');
});

test('le digest d\'activité ventile les actions par employé et atteint le numéro admin', function () {
    Setting::set('whatsapp.admin_phone', '+224677777777');

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-DIGEST',
        'name' => 'Client Digest', 'type' => 'particulier', 'status' => 'actif',
        'credit_limit' => 0, 'balance' => 0,
    ]);

    // L'opérateur saisit 2 ventes et encaisse 1 paiement aujourd'hui.
    $sale = Sale::create([
        'farm_id' => $this->farm->id, 'client_id' => $client->id, 'user_id' => $this->operatorUser->id,
        'reference' => 'BL-DIG-1', 'sale_date' => now()->toDateString(),
        'type' => 'bon_livraison', 'status' => 'valide',
        'subtotal' => 300000, 'tax_amount' => 0, 'total_amount' => 300000,
        'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);
    Sale::create([
        'farm_id' => $this->farm->id, 'client_id' => $client->id, 'user_id' => $this->operatorUser->id,
        'reference' => 'BL-DIG-2', 'sale_date' => now()->toDateString(),
        'type' => 'bon_livraison', 'status' => 'valide',
        'subtotal' => 150000, 'tax_amount' => 0, 'total_amount' => 150000,
        'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);
    Payment::create([
        'farm_id' => $this->farm->id, 'sale_id' => $sale->id, 'amount' => 100000,
        'payment_date' => now()->toDateString(), 'method' => 'especes', 'received_by' => $this->operatorUser->id,
    ]);

    // Le manager fait un mouvement de stock.
    $stock = Stock::factory()->create([
        'category' => Stock::CAT_MATERIELS, 'item_name' => 'Caisses', 'unit' => 'Unité',
        'current_quantity' => 50, 'alert_threshold' => 0,
    ]);
    StockMovement::create([
        'stock_id' => $stock->id, 'user_id' => $this->managerUser->id,
        'type' => 'out', 'quantity' => 5, 'notes' => 'Sortie test',
    ]);

    $sent = app(NotificationHub::class)->sendActivityDigest();

    expect($sent)->toBeGreaterThan(0);

    $log = NotificationLog::where('type', 'activity_digest')->where('status', 'sent')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->recipient_phone)->toBe('+224677777777')
        ->and($log->message)->toContain($this->operatorUser->name)
        ->and($log->message)->toContain('Ventes : 2')
        ->and($log->message)->toContain('Encaissé : 1')
        ->and($log->message)->toContain($this->managerUser->name)
        ->and($log->message)->toContain('1 sortie(s)');
});

test('le digest d\'activité n\'envoie rien en l\'absence d\'activité', function () {
    Setting::set('whatsapp.admin_phone', '+224677777777');

    $sent = app(NotificationHub::class)->sendActivityDigest();

    expect($sent)->toBe(0)
        ->and(NotificationLog::where('type', 'activity_digest')->count())->toBe(0);
});
