<?php

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Services\WebPushService;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * PUSH NAVIGATEUR — faire sonner le téléphone application FERMÉE.
 *
 * La cloche web et le centre d'alertes mobile sont des ÉCRANS : ils ne se
 * remplissent qu'à l'ouverture de l'application. Une mortalité critique à
 * Kérouané attendait donc que quelqu'un ouvre l'app — ce qui, pour un promoteur à
 * l'étranger, revient à ne pas être alerté.
 *
 * Le push est le seul canal qui atteigne le terrain sans ça. Il vient EN PLUS de
 * la cloche, qui reste la source consultable : un téléphone éteint ou un
 * abonnement expiré ne doit pas faire perdre l'information.
 */

beforeEach(function () {
    $this->setUpRbac();
    Setting::clearCache();
});

/** Faux abonnement d'appareil — les clefs sont du bruit de la bonne longueur. */
function fakeSubscription(int $userId, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): PushSubscription
{
    return PushSubscription::register($userId, [
        'endpoint' => $endpoint,
        'keys'     => [
            'p256dh' => 'BLc4xRzKlKORKWlbdgFaBrrPK3ydWAHo4M0gs0i1oEKgPpWC5cW8OCzVrOQRv-1npXRWk8udnW3oYhIO4475rds',
            'auth'   => 'lFdgHJlOe1x8VqTYPRQAqQ',
        ],
    ], 'Android — fr');
}

test('sans clef VAPID, le push est désactivé et le dit', function () {
    // Aucune clef : le serveur ne peut rien envoyer. Il faut le DIRE au client
    // plutôt que de le laisser tenter un abonnement qui échouera.
    $push = app(WebPushService::class);

    expect($push->isConfigured())->toBeFalse();

    $token = $this->adminUser->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/push/key')
        ->assertOk()
        ->assertJson(['configured' => false]);
});

test('la commande génère une paire de clefs VAPID', function () {
    $this->artisan('push:generate-keys')->assertSuccessful();

    Setting::clearCache();
    $push = app(WebPushService::class);

    expect($push->isConfigured())->toBeTrue()
        ->and(strlen($push->publicKey()))->toBeGreaterThan(80);
});

test('la commande REFUSE d’écraser une paire existante sans --force', function () {
    // Remplacer la clef publique invalide TOUS les abonnements : chaque téléphone
    // devrait réaccepter les notifications. Ça ne doit pas arriver par accident.
    $this->artisan('push:generate-keys')->assertSuccessful();
    Setting::clearCache();

    $before = app(WebPushService::class)->publicKey();

    $this->artisan('push:generate-keys')->assertFailed();
    Setting::clearCache();

    expect(app(WebPushService::class)->publicKey())->toBe($before);
});

test('un appareil s’abonne, et se réabonner ne crée pas de doublon', function () {
    $this->artisan('push:generate-keys');
    Setting::clearCache();

    $token = $this->adminUser->createToken('mobile')->plainTextToken;
    $payload = [
        'endpoint'     => 'https://fcm.googleapis.com/fcm/send/xyz',
        'keys'         => ['p256dh' => 'cle-publique-appareil', 'auth' => 'secret-auth'],
        'device_label' => 'Android — fr',
    ];

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/push/subscribe', $payload)->assertOk();

    // Réinstallation de l'app : le même appareil se réabonne. Deux lignes lui
    // enverraient DEUX FOIS chaque alerte.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/push/subscribe', $payload)->assertOk();

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::first()->device_label)->toBe('Android — fr');
});

test('un même utilisateur peut abonner PLUSIEURS appareils', function () {
    // Téléphone de service et téléphone personnel : les deux doivent sonner.
    fakeSubscription($this->adminUser->id, 'https://fcm.googleapis.com/fcm/send/telephone-1');
    fakeSubscription($this->adminUser->id, 'https://fcm.googleapis.com/fcm/send/telephone-2');

    expect(PushSubscription::where('user_id', $this->adminUser->id)->count())->toBe(2);
});

test('on ne peut désabonner que SES appareils', function () {
    // L'endpoint est une chaîne fournie par le client : il ne doit pas permettre
    // de faire taire le téléphone de quelqu'un d'autre.
    $other = fakeSubscription($this->adminUser->id, 'https://fcm.googleapis.com/fcm/send/promoteur');

    $token = $this->readonlyUser->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/push/unsubscribe', ['endpoint' => $other->endpoint])
        ->assertOk();

    expect(PushSubscription::find($other->id))->not->toBeNull();
});

test('le canal push rejoint les canaux de l’alerte quand il est configuré', function () {
    $this->artisan('push:generate-keys');
    Setting::clearCache();

    $notification = new \App\Notifications\AlertNotification(
        ['type' => 'alert_mortality', 'title' => 'Mortalité critique', 'message' => 'Lot CH-001 : 6,2 %'],
        ['database', 'webpush']
    );

    expect($notification->via($this->adminUser))->toContain('webpush');

    // La charge utile push reste SOBRE : elle s'affiche sur un écran verrouillé.
    $payload = $notification->toWebPush($this->adminUser);

    expect($payload)->toHaveKeys(['title', 'body', 'severity', 'url', 'tag'])
        ->and($payload['title'])->toBe('Mortalité critique')
        ->and($payload['tag'])->toBe('alert_mortality');
});

test('couper le canal push n’ampute pas la cloche', function () {
    // Le push est un CONFORT : le refuser ne doit pas priver de l'historique.
    NotificationPreference::updateOrCreate(
        ['user_id' => $this->adminUser->id],
        array_merge(NotificationPreference::DEFAULTS, ['channel_push' => false])
    );

    app(\App\Services\NotificationHub::class)->alertHaccp('Registre incomplet', 'Températures');

    expect($this->adminUser->fresh()->notifications()->count())->toBe(1);
});

test('sans clef VAPID, aucune alerte ne se perd', function () {
    // Le push non configuré ne doit pas empêcher l'émission : la cloche passe.
    expect(app(WebPushService::class)->isConfigured())->toBeFalse();

    app(\App\Services\NotificationHub::class)->alertHaccp('Registre incomplet', 'Températures');

    expect($this->adminUser->fresh()->notifications()->count())->toBe(1);
});

test('un envoi sans appareil abonné ne casse rien', function () {
    $this->artisan('push:generate-keys');
    Setting::clearCache();

    $result = app(WebPushService::class)->sendToUser($this->adminUser, [
        'title' => 'Test', 'body' => 'Message',
    ]);

    expect($result)->toBe(['sent' => 0, 'pruned' => 0, 'failed' => 0]);
});

test('le service worker porte les écouteurs de push', function () {
    // Sans ces deux écouteurs, le téléphone reçoit le message et ne fait rien.
    $sw = file_get_contents(base_path('mobile/public/push-sw.js'));

    expect($sw)->toContain("addEventListener('push'")
        ->and($sw)->toContain("addEventListener('notificationclick'")
        // Réutiliser l'onglet ouvert : sinon chaque alerte empile une fenêtre.
        ->and($sw)->toContain('clients.matchAll');

    // Et il est bien importé par le service worker généré.
    $config = file_get_contents(base_path('mobile/vite.config.ts'));
    expect($config)->toContain("importScripts: ['/push-sw.js']");
});

test('le client mobile sait dire POURQUOI le push est impossible', function () {
    // Un échec muet fait croire l'application défaillante. Trois causes distinctes
    // doivent être nommées : navigateur, clef serveur, autorisation refusée.
    $module = file_get_contents(base_path('mobile/src/offline/push.ts'));

    foreach (['unsupported', 'not_configured', 'denied'] as $state) {
        expect($module)->toContain("'{$state}'");
    }

    // Le cas iPhone, qui exige l'installation sur l'écran d'accueil.
    expect($module)->toContain('iosNeedsInstall')
        ->and($module)->toContain("écran d'accueil");
});
