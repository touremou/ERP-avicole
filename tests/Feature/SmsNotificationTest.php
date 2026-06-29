<?php

use App\Models\NotificationLog;
use App\Models\Setting;
use App\Notifications\IndustrialAlert;
use App\Services\SmsService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
});

test('le driver SMS « log » n\'appelle aucune passerelle et journalise l\'envoi', function () {
    Http::fake(); // aucune requête ne doit partir
    Setting::set('sms.driver', 'log');

    $ok = app(SmsService::class)->send('+224620000000', 'Bonjour', ['type' => 'test']);

    expect($ok)->toBeTrue();
    Http::assertNothingSent();
    expect(NotificationLog::where('channel', 'sms')->where('status', 'sent')->exists())->toBeTrue();
});

test('le driver SMS « http » poste vers la passerelle configurée', function () {
    Setting::set('sms.driver', 'http');
    Setting::set('sms.api_url', 'https://gw.test/sms/send');
    Http::fake(['gw.test/*' => Http::response('OK', 200)]);

    $ok = app(SmsService::class)->send('+224620000000', 'Alerte', ['type' => 'test']);

    expect($ok)->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'gw.test') && $req['to'] === '+224620000000');
    expect(NotificationLog::where('channel', 'sms')->where('status', 'sent')->exists())->toBeTrue();
});

test('un échec de passerelle SMS est journalisé sans lever d\'exception', function () {
    Setting::set('sms.driver', 'http');
    Setting::set('sms.api_url', 'https://gw.test/sms/send');
    Http::fake(['gw.test/*' => Http::response('ko', 500)]);

    $ok = app(SmsService::class)->send('+224620000000', 'Alerte', ['type' => 'test']);

    expect($ok)->toBeFalse()
        ->and(NotificationLog::where('channel', 'sms')->where('status', 'failed')->exists())->toBeTrue();
});

test('http sans URL de passerelle échoue proprement (pas d\'envoi)', function () {
    Http::fake();
    Setting::set('sms.driver', 'http');
    Setting::set('sms.api_url', '');

    expect(app(SmsService::class)->send('+224620000000', 'X'))->toBeFalse();
    Http::assertNothingSent();
});

test('toSms cible le mobile (whatsapp_phone), pas une colonne phone inexistante', function () {
    $this->adminUser->update(['whatsapp_phone' => '+224611223344']);

    $payload = (new IndustrialAlert(['message' => 'Test', 'priority' => 'high']))->toSms($this->adminUser->fresh());

    expect($payload['to'])->toBe('+224611223344');
});

test('le bouton de test SMS répond (mode log)', function () {
    Setting::set('sms.driver', 'log');
    $this->adminUser->update(['whatsapp_phone' => '+224620000000']);

    $this->actingAs($this->adminUser)
        ->post(route('notifications.test_sms'))
        ->assertSessionHas('success');
});

test('le bouton de test e-mail répond', function () {
    $this->actingAs($this->adminUser)
        ->post(route('notifications.test_mail'))
        ->assertSessionHas('success');
});
