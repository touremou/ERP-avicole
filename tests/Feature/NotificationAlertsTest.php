<?php

use App\Actions\Sale\CancelSale;
use App\Actions\Stock\MoveStockAction;
use App\Models\Client;
use App\Models\DiscrepancyReport;
use App\Models\NotificationLog;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Stock;
use App\Services\NotificationHub;
use App\Services\StockIntegrationService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Setting::set('whatsapp.driver', 'log');
});

// ── (f) TEST WHATSAPP ──

test('le test whatsapp demande le numéro personnel même si l\'API est configurée', function () {
    // L'admin a configuré l'API (driver/clé) mais n'a pas encore enregistré
    // son numéro personnel dans Notifications > Préférences.
    Setting::set('whatsapp.api_key', 'fake-key');

    $this->actingAs($this->adminUser)
        ->post(route('notifications.test'))
        ->assertSessionHas('error');

    expect(session('error'))->toContain('Renseignez votre numéro');
});

test('le test whatsapp signale le mode log si aucun provider n\'est actif', function () {
    $this->adminUser->update(['whatsapp_phone' => '+224620000000']);

    $this->actingAs($this->adminUser)
        ->post(route('notifications.test'))
        ->assertSessionHas('error');

    expect(session('error'))->toContain('mode "log"');
});

test('la page de préférences pré-remplit le numéro depuis whatsapp.admin_phone', function () {
    Setting::set('whatsapp.admin_phone', '+224699999999');

    $response = $this->actingAs($this->adminUser)->get(route('notifications.preferences'));

    $response->assertOk();
    $response->assertSee('+224699999999');
});

// ── (g) ALERTES TEMPS RÉEL — VISIBILITÉ ADMIN HORS SITE ──

test('un écart critique déclenche une alerte WhatsApp via le numéro admin de secours', function () {
    Setting::set('whatsapp.admin_phone', '+224611111111');

    $dispatch = App\Models\Dispatch::create([
        'dispatch_number' => 'EXP-TEST-001',
        'dispatched_by'   => $this->adminUser->id,
        'driver_name'     => 'Chauffeur Test',
        'dispatch_date'   => now()->toDateString(),
        'destination'     => 'Marché Central',
        'status'          => 'expedie',
    ]);

    $reception = App\Models\Reception::create([
        'dispatch_id'      => $dispatch->id,
        'reception_number' => 'REC-TEST-001',
        'received_by'      => $this->adminUser->id,
        'reception_date'   => now()->toDateString(),
        'status'           => 'litige',
    ]);

    $report = DiscrepancyReport::create([
        'dispatch_id'      => $dispatch->id,
        'reception_id'     => $reception->id,
        'reported_by'      => $this->adminUser->id,
        'total_dispatched' => 100,
        'total_received'   => 80,
        'total_damaged'    => 0,
        'total_missing'    => 20,
        'discrepancy_rate' => 20,
        'severity'         => 'critique',
        'resolution'       => 'en_cours',
    ]);

    app(NotificationHub::class)->alertFraud($report);

    expect(NotificationLog::where('type', 'alert_fraud')->where('status', 'sent')->exists())->toBeTrue();
});

test('le franchissement du seuil de stock déclenche une alerte au numéro admin de secours', function () {
    Setting::set('whatsapp.admin_phone', '+224622222222');

    $stock = Stock::factory()->create([
        'category'        => Stock::CAT_CONSO,
        'item_name'       => 'Aliment Démarrage',
        'unit'            => 'KG',
        'current_quantity'=> 100,
        'alert_threshold' => 50,
    ]);

    StockIntegrationService::syncMovement(
        'Aliment Démarrage',
        Stock::CAT_CONSO,
        60,
        'out',
        'Test consommation',
        'KG'
    );

    expect($stock->refresh()->current_quantity)->toBe('40.000');
    expect(NotificationLog::where('type', 'alert_stock')->where('status', 'sent')->exists())->toBeTrue();
});

test('un nouveau mouvement de stock sous le seuil ne ré-alerte pas une seconde fois', function () {
    Setting::set('whatsapp.admin_phone', '+224622222222');

    $stock = Stock::factory()->create([
        'category'        => Stock::CAT_CONSO,
        'item_name'       => 'Aliment Finition',
        'unit'            => 'KG',
        'current_quantity'=> 40,
        'alert_threshold' => 50,
    ]);

    StockIntegrationService::syncMovement(
        'Aliment Finition',
        Stock::CAT_CONSO,
        5,
        'out',
        'Test consommation 2',
        'KG'
    );

    expect(NotificationLog::where('type', 'alert_stock')->count())->toBe(0);
});

test('une vente au-delà du seuil est escaladée en alerte critique', function () {
    Setting::set('whatsapp.admin_phone', '+224633333333');
    Setting::set('whatsapp.large_sale_threshold', '1000000');

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-LARGE',
        'name' => 'Grossiste', 'type' => 'particulier', 'status' => 'actif',
        'credit_limit' => 0, 'balance' => 0,
    ]);

    $sale = Sale::create([
        'farm_id' => $this->farm->id, 'client_id' => $client->id, 'user_id' => $this->adminUser->id,
        'reference' => 'BL-LARGE-001', 'sale_date' => now()->toDateString(),
        'type' => 'bon_livraison', 'status' => 'valide',
        'subtotal' => 5000000, 'tax_amount' => 0, 'total_amount' => 5000000,
        'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);

    app(NotificationHub::class)->notifySaleCreated($sale->fresh(['client']));

    // Escaladée → délivrée au numéro admin de secours (statut sent).
    expect(NotificationLog::where('type', 'alert_sales')->where('status', 'sent')->exists())->toBeTrue();
});

test('annuler une vente validée déclenche une alerte anti-fraude critique', function () {
    Setting::set('whatsapp.admin_phone', '+224644444444');

    $client = Client::create([
        'farm_id' => $this->farm->id, 'client_id' => 'CLI-CANCEL',
        'name' => 'Client Annul', 'type' => 'particulier', 'status' => 'actif',
        'credit_limit' => 0, 'balance' => 0,
    ]);

    $sale = Sale::create([
        'farm_id' => $this->farm->id, 'client_id' => $client->id, 'user_id' => $this->adminUser->id,
        'reference' => 'BL-CANCEL-001', 'sale_date' => now()->toDateString(),
        'type' => 'bon_livraison', 'status' => 'valide',
        'subtotal' => 200000, 'tax_amount' => 0, 'total_amount' => 200000,
        'paid_amount' => 0, 'payment_status' => 'impaye',
    ]);

    $this->actingAs($this->adminUser);
    (new CancelSale())->execute($sale, 'Erreur de saisie');

    $log = NotificationLog::where('type', 'alert_fraud')->where('status', 'sent')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->message)->toContain('VENTE ANNULÉE');
});

test('un ajustement manuel de stock à la baisse déclenche une alerte anti-fraude', function () {
    Setting::set('whatsapp.admin_phone', '+224655555555');

    $stock = Stock::factory()->create([
        'category'        => Stock::CAT_MATERIELS,
        'item_name'       => 'Sacs vides',
        'unit'            => 'Unité',
        'current_quantity'=> 100,
        'alert_threshold' => 0,
    ]);

    $this->actingAs($this->adminUser);
    (new MoveStockAction())->execute($stock->id, 'adjustment', 60, 'Inventaire physique', $this->adminUser->id);

    $log = NotificationLog::where('type', 'alert_fraud')->where('status', 'sent')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->message)->toContain('AJUSTEMENT STOCK');
});
