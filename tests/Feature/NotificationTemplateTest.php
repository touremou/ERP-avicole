<?php

use App\Models\Batch;
use App\Models\NotificationTemplate;
use App\Services\NotificationHub;
use App\Services\WhatsAppService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

// ─── Moteur de rendu ──────────────────────────────────────────────────────────

test('l\'interpolation remplace les variables {{ clé }}', function () {
    $out = NotificationTemplate::interpolate('Lot *{{ code }}* — {{ n }} morts', ['code' => 'LOT-1', 'n' => 4]);

    expect($out)->toBe('Lot *LOT-1* — 4 morts');
});

test('une variable inconnue est remplacée par une chaîne vide', function () {
    expect(NotificationTemplate::interpolate('A {{ x }} B', []))->toBe('A  B');
});

test('bodyFor retombe sur le défaut livré quand aucun modèle actif n\'existe', function () {
    // Aucune ligne en base : on doit obtenir le défaut du catalogue.
    expect(NotificationTemplate::bodyFor('alert_stock'))
        ->toBe(NotificationTemplate::catalog()['alert_stock']['default']);
});

test('bodyFor utilise le modèle de la base quand il est actif', function () {
    NotificationTemplate::where('key', 'alert_stock')
        ->update(['body' => 'PERSONNALISÉ {{ item_name }}', 'is_active' => true]);
    NotificationTemplate::clearCache();

    expect(NotificationTemplate::bodyFor('alert_stock'))->toBe('PERSONNALISÉ {{ item_name }}');
});

test('un modèle désactivé est ignoré au profit du défaut', function () {
    NotificationTemplate::where('key', 'alert_stock')
        ->update(['body' => 'IGNORÉ', 'is_active' => false]);
    NotificationTemplate::clearCache();

    expect(NotificationTemplate::bodyFor('alert_stock'))
        ->toBe(NotificationTemplate::catalog()['alert_stock']['default']);
});

// ─── Intégration NotificationHub ───────────────────────────────────────────────

test('un modèle personnalisé change le message d\'alerte envoyé', function () {
    NotificationTemplate::where('key', 'alert_mortality')
        ->update(['body' => 'MORT {{ batch_code }} = {{ deaths }}', 'is_active' => true]);
    NotificationTemplate::clearCache();

    // Filet admin : une alerte critique part vers ce numéro même sans abonné.
    \App\Models\Setting::set('whatsapp.admin_phone', '620000000');

    // On capture le message transmis au service WhatsApp.
    $captured = null;
    $fake = Mockery::mock(WhatsAppService::class);
    $fake->shouldReceive('send')->andReturnUsing(function ($phone, $msg) use (&$captured) {
        $captured = $msg;
        return true;
    });

    $building = \App\Models\Building::factory()->create();
    $batch = Batch::factory()->create(['building_id' => $building->id, 'code' => 'LOT-MORT', 'current_quantity' => 90]);

    $hub = new NotificationHub($fake);
    $hub->alertMortality($batch, 10, 5.0);

    expect($captured)->toContain('MORT LOT-MORT = 10');
});

// ─── IHM d'administration ────────────────────────────────────────────────────

test('l\'admin peut afficher et mettre à jour un modèle', function () {
    $this->actingAs($this->adminUser)
        ->get(route('notifications.templates'))
        ->assertOk()
        ->assertSee('Rupture de stock');

    $template = NotificationTemplate::where('key', 'alert_stock')->first();

    $this->actingAs($this->adminUser)
        ->put(route('notifications.templates.update', $template->id), [
            'body'      => 'Nouveau texte {{ item_name }}',
            'is_active' => '1',
        ])
        ->assertRedirect();

    expect($template->fresh()->body)->toBe('Nouveau texte {{ item_name }}');
});

test('réinitialiser restaure le texte d\'origine', function () {
    $template = NotificationTemplate::where('key', 'alert_fuel')->first();
    $template->update(['body' => 'bidouillé']);

    $this->actingAs($this->adminUser)
        ->put(route('notifications.templates.reset', $template->id))
        ->assertRedirect();

    expect($template->fresh()->body)
        ->toBe(NotificationTemplate::catalog()['alert_fuel']['default']);
});
