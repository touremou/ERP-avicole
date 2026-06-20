<?php

use App\Models\NotificationLog;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Setting::set('whatsapp.driver', 'callmebot');
    Setting::set('whatsapp.api_key', 'fake-key');
});

test('une notification whatsapp en échec est retentée et marquée envoyée', function () {
    // Premier envoi en échec (panne API), puis la connexion est rétablie au
    // moment du passage de la commande de relance.
    Http::fake([
        'api.callmebot.com/*' => Http::sequence()
            ->push('', 500)
            ->push('Message envoyé', 200),
    ]);

    $result = app(WhatsAppService::class)->send('+224620000000', 'Test message', [
        'type'  => 'alert_stock',
        'title' => 'Stock',
    ]);

    expect($result)->toBeFalse();

    $log = NotificationLog::where('type', 'alert_stock')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('failed')
        ->and($log->attempts)->toBe(1)
        ->and($log->recipient_phone)->toBe('+224620000000');

    $this->artisan('avismart:retry-failed-notifications')->assertExitCode(0);

    $log->refresh();
    expect($log->status)->toBe('sent')
        ->and($log->attempts)->toBe(2)
        ->and($log->sent_at)->not->toBeNull();
});

test('une notification ayant atteint le nombre maximal de tentatives n\'est plus retentée', function () {
    $log = NotificationLog::create([
        'channel'         => 'whatsapp',
        'type'            => 'alert_stock',
        'title'           => 'Stock',
        'message'         => 'Test message',
        'recipient_phone' => '+224620000000',
        'status'          => 'failed',
        'attempts'        => 5,
    ]);

    Http::fake([
        'api.callmebot.com/*' => Http::response('Message envoyé', 200),
    ]);

    $this->artisan('avismart:retry-failed-notifications')->assertExitCode(0);

    $log->refresh();
    expect($log->status)->toBe('failed')
        ->and($log->attempts)->toBe(5);
});

test('une notification en échec trop ancienne n\'est plus retentée', function () {
    Carbon\Carbon::setTestNow(now()->subDays(2));

    $log = NotificationLog::create([
        'channel'         => 'whatsapp',
        'type'            => 'alert_stock',
        'title'           => 'Stock',
        'message'         => 'Test message',
        'recipient_phone' => '+224620000000',
        'status'          => 'failed',
        'attempts'        => 1,
    ]);

    Carbon\Carbon::setTestNow();

    Http::fake([
        'api.callmebot.com/*' => Http::response('Message envoyé', 200),
    ]);

    $this->artisan('avismart:retry-failed-notifications')->assertExitCode(0);

    $log->refresh();
    expect($log->status)->toBe('failed')
        ->and($log->attempts)->toBe(1);
});

// ── RÉSILIENCE SSL (cURL error 60) ──

test('le bundle CA fourni par composer pointe vers un fichier valide', function () {
    // Garantit que la parade « cURL error 60 » reste opérationnelle :
    // composer/ca-bundle doit toujours résoudre un bundle CA exploitable.
    $path = Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();

    expect(is_string($path))->toBeTrue()
        ->and(is_file($path))->toBeTrue();
});

test('l\'envoi fonctionne même avec la vérification SSL désactivée (dernier recours)', function () {
    Setting::set('whatsapp.verify_ssl', '0');

    Http::fake(['api.callmebot.com/*' => Http::response('Message envoyé', 200)]);

    $result = app(WhatsAppService::class)->send('+224620000000', 'Test sans SSL', [
        'type' => 'test', 'title' => 'Test',
    ]);

    expect($result)->toBeTrue();
    expect(NotificationLog::where('type', 'test')->where('status', 'sent')->exists())->toBeTrue();
});
