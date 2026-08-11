<?php

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE ALERTE DOIT ATTEINDRE LE TÉLÉPHONE, PAS SEULEMENT L'ÉCRAN DU PROMOTEUR.
 *
 * Signalé depuis le terrain : « j'aimerais que ces notifications web soient aussi
 * poussées vers le mobile ; je ne pense pas que ce soit le cas ».
 *
 * Le miroir mobile existait bel et bien : l'API `/api/v1/notifications` renvoie
 * les notifications de l'utilisateur, et le cycle de synchronisation de la PWA
 * les recopie en local (au lancement, au retour du réseau, puis toutes les dix
 * minutes). Le défaut était EN AMONT.
 *
 * `NotificationHub::typeRecipients()` exigeait une ligne de préférences ACTIVE
 * (`whereHas`). Or cette ligne n'était créée qu'en ouvrant l'écran Paramètres ›
 * Notifications. Un compte qui n'y était jamais allé recevait donc ZÉRO alerte
 * in-app — ni cloche web, ni centre d'alertes mobile — et aucun message ne le
 * signalait. Le promoteur, qui avait visité l'écran, recevait tout ; ses
 * techniciens, rien.
 *
 * Pour un système d'alerte, le silence par omission est le pire des défauts : ne
 * rien recevoir est indistinguable de « tout va bien ».
 */

beforeEach(function () {
    $this->setUpRbac();
    NotificationPreference::query()->delete();   // on repart d'un état « jamais réglé »
});

test('un compte SANS préférence enregistrée reçoit quand même l’alerte', function () {
    // Le cas des techniciens : ils n'ont jamais ouvert l'écran des réglages.
    expect(NotificationPreference::where('user_id', $this->readonlyUser->id)->exists())->toBeFalse();

    app(NotificationHub::class)->alertHaccp(
        'Registre des températures incomplet : 0/2 relevés.',
        'Relevés de température manquants',
    );

    expect($this->readonlyUser->fresh()->notifications()->count())->toBe(1);
});

test('la diffusion n’écrit PAS de préférences au passage', function () {
    // Un chemin de lecture ne doit pas créer de lignes en base.
    app(NotificationHub::class)->alertHaccp('Message', 'Titre');

    expect(NotificationPreference::count())->toBe(0);
});

test('une préférence explicitement DÉSACTIVÉE est respectée', function () {
    NotificationPreference::create(array_merge(
        NotificationPreference::DEFAULTS,
        ['user_id' => $this->readonlyUser->id, 'is_active' => false]
    ));

    app(NotificationHub::class)->alertHaccp('Message', 'Titre');

    expect($this->readonlyUser->fresh()->notifications()->count())->toBe(0);
    // Et l'autre compte, sans préférence, la reçoit bien.
    expect($this->adminUser->fresh()->notifications()->count())->toBe(1);
});

test('couper la cloche sans couper les préférences est respecté', function () {
    NotificationPreference::create(array_merge(
        NotificationPreference::DEFAULTS,
        ['user_id' => $this->readonlyUser->id, 'channel_database' => false]
    ));

    app(NotificationHub::class)->alertHaccp('Message', 'Titre');

    expect($this->readonlyUser->fresh()->notifications()->count())->toBe(0);
});

test('l’alerte arrive au MOBILE par le même chemin que la cloche web', function () {
    // C'est la question posée : la version mobile reçoit-elle les mêmes alertes ?
    app(NotificationHub::class)->alertHaccp(
        'Registre des températures incomplet : 0/2 relevés.',
        'Relevés de température manquants',
    );

    $token = $this->readonlyUser->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('notifications.0.title', 'Relevés de température manquants');
});

test('un nouveau compte reçoit ses préférences dès sa création', function () {
    // Ainsi la ferme les VOIT et peut les régler, au lieu d'un implicite.
    $user = User::factory()->create();

    expect(NotificationPreference::where('user_id', $user->id)->exists())->toBeTrue();
});

test('la migration comble les comptes existants', function () {
    // Les comptes créés avant ce lot n'avaient pas de ligne du tout.
    expect(NotificationPreference::count())->toBe(0);

    $migration = require database_path('migrations/2026_08_10_000000_seed_missing_notification_preferences.php');
    $migration->up();

    expect(NotificationPreference::count())->toBe(User::count())
        ->and(NotificationPreference::where('channel_database', true)->count())->toBe(User::count());
});

test('les valeurs livrées sont déclarées en UN endroit', function () {
    // Elles étaient écrites en dur dans le contrôleur des préférences.
    expect(NotificationPreference::DEFAULTS['channel_database'])->toBeTrue()
        ->and(NotificationPreference::DEFAULTS['is_active'])->toBeTrue()
        // Le canal e-mail et les alertes de vente restent sur choix explicite.
        ->and(NotificationPreference::DEFAULTS['channel_email'])->toBeFalse()
        ->and(NotificationPreference::DEFAULTS['alert_sales'])->toBeFalse();

    $controller = file_get_contents(app_path('Http/Controllers/NotificationController.php'));
    expect($controller)->toContain('NotificationPreference::forUser')
        ->and($controller)->not->toContain("'channel_whatsapp'  => true");
});

test('le canal WhatsApp reste sur consentement EXPLICITE', function () {
    // Un message sur le téléphone de quelqu'un coûte de l'argent et s'impose à
    // lui : contrairement à la cloche, il ne s'active pas par défaut faute de
    // réglage. Un compte sans préférence ne doit recevoir aucun WhatsApp.
    //
    // L'ASSERTION PORTE SUR getSubscribers(), ET SUR ELLE SEULE. Auparavant elle
    // cherchait « ->whereNotNull('whatsapp_phone') » dans TOUT le fichier : elle
    // était donc satisfaite par une ligne sans rapport — la requête des valideurs
    // de congé — et serait restée verte si getSubscribers avait perdu son filtre.
    // Le jour où cette autre ligne a été retirée à bon droit, le test a crié pour
    // une raison qui n'était pas la sienne. Un garde-fou qui surveille autre chose
    // que ce qu'il annonce ne garde rien.
    $method = new ReflectionMethod(\App\Services\NotificationHub::class, 'getSubscribers');

    $body = implode('', array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));

    expect($body)->toContain("whereNotNull('whatsapp_phone')")
        // …et le consentement explicite : une préférence enregistrée, cochée.
        ->and($body)->toContain('channel_whatsapp')
        ->and($body)->toContain('whereHas');

    // La résolution in-app, elle, admet l'absence de préférence : la cloche est
    // gratuite et non intrusive, elle suit les valeurs livrées.
    $inApp = new ReflectionMethod(\App\Services\NotificationHub::class, 'typeRecipients');

    $inAppBody = implode('', array_slice(
        file($inApp->getFileName()),
        $inApp->getStartLine() - 1,
        $inApp->getEndLine() - $inApp->getStartLine() + 1
    ));

    expect($inAppBody)->toContain('orWhereDoesntHave');
});

test('un compte sans téléphone WhatsApp ne bloque pas la cloche', function () {
    // Le cas d'un technicien sans numéro renseigné : il doit voir ses alertes
    // dans l'application, faute de pouvoir les recevoir par message.
    DB::table('users')->where('id', $this->readonlyUser->id)->update(['whatsapp_phone' => null]);

    app(NotificationHub::class)->alertHaccp('Message', 'Titre');

    expect($this->readonlyUser->fresh()->notifications()->count())->toBe(1);
});
