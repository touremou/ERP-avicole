<?php

use App\Models\NotificationLog;
use App\Notifications\AlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Helpers\AviSmartTestHelper;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * LE CANAL E-MAIL N'AVAIT PAS DE JOURNAL.
 *
 * `notification_logs` traçait les envois WhatsApp (`WhatsAppService`) et SMS
 * (`SmsService`), et l'écran « Historique des notifications » affiche
 * envoyés/échoués à partir de cette table. Le canal e-mail n'écrivait RIEN —
 * ni succès, ni échec, ni tentative.
 *
 * À la question « je ne reçois pas les mails », l'application ne pouvait donc
 * pas répondre. Trois situations très différentes se ressemblaient depuis le
 * fauteuil du promoteur, qui est à l'étranger et n'a pas le serveur sous la
 * main :
 *
 *   • personne n'a coché la case E-mail (channel_email vaut FAUX par défaut) ;
 *   • le SMTP refuse — identifiants, ou expéditeur différent du compte
 *     authentifié, ce que la plupart des hébergeurs mutualisés rejettent ;
 *   • le message est bien parti et s'est perdu côté boîte de réception.
 *
 * L'absence de trace était indistinguable d'un échec silencieux.
 *
 * ─── POURQUOI UNE LIGNE POSÉE AVANT L'ENVOI ───
 *
 * Laravel signale l'échec du canal mail (`NotificationFailed`) : la ligne passe
 * bien à « échoué », avec le motif du serveur. La ligne posée AVANT l'envoi
 * n'est donc pas le mécanisme principal — c'est le filet : si un envoi meurt
 * sans qu'aucun des deux évènements ne parte, il reste « en cours », ce qui se
 * lit comme « tenté, jamais abouti ». Aucun chemin ne peut ne rien laisser.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->setUpBaseData();
});

test('un e-mail envoyé LAISSE une ligne « envoyé »', function () {
    /*
     * LE défaut : cet envoi ne laissait aucune trace.
     */
    $this->adminUser->notify(new AlertNotification(
        ['type' => 'daily_summary', 'title' => 'Résumé Quotidien', 'message' => 'Effectif 1491'],
        ['mail'],
    ));

    $log = NotificationLog::where('channel', 'mail')->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('sent')
        ->and($log->type)->toBe('daily_summary')
        ->and($log->title)->toBe('Résumé Quotidien')
        ->and($log->recipient_email)->toBe($this->adminUser->email)
        ->and($log->user_id)->toBe($this->adminUser->id)
        ->and($log->sent_at)->not->toBeNull();
});

test('un envoi REFUSÉ par le serveur laisse une ligne « échoué » avec le motif', function () {
    /*
     * Le cas qui compte le plus : le SMTP qui refuse — identifiants, ou
     * expéditeur différent du compte authentifié, ce que la plupart des
     * hébergeurs mutualisés rejettent. C'est précisément le message que
     * l'écran d'historique doit rendre lisible, plutôt qu'un silence.
     */
    \Illuminate\Support\Facades\Mail::shouldReceive('mailer')->andThrow(
        new \RuntimeException('SMTP: authentification refusée'),
    );

    try {
        $this->adminUser->notify(new AlertNotification(
            ['type' => 'alert_mortality', 'title' => 'Mortalité', 'message' => 'Pic détecté'],
            ['mail'],
        ));
    } catch (\Throwable) {
        // Selon la version, l'exception peut aussi remonter : ce n'est pas
        // l'objet du test. Ce qu'on vérifie, c'est la trace laissée derrière.
    }

    $log = NotificationLog::where('channel', 'mail')->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('failed')
        ->and($log->sent_at)->toBeNull()
        // Le MOTIF du serveur, lisible : c'est ce que l'écran d'historique
        // affiche, et ce sur quoi le promoteur peut agir.
        ->and($log->provider_response['error'])->toContain('authentification refusée');
});

test('une alerte SANS canal e-mail n’écrit aucune ligne mail', function () {
    /*
     * La borne. Une alerte in-app ne doit pas peupler le journal e-mail : sinon
     * l'écran annoncerait des envois qui n'ont jamais eu lieu — l'inverse
     * exact du défaut qu'on corrige.
     */
    $this->adminUser->notify(new AlertNotification(
        ['type' => 'alert_stock', 'title' => 'Stock', 'message' => 'Seuil atteint'],
        ['database'],
    ));

    expect(NotificationLog::where('channel', 'mail')->count())->toBe(0);
});

test('le filet ADMIN sur adresse anonyme est tracé lui aussi', function () {
    /*
     * Les alertes critiques partent aussi vers `whatsapp.admin_email`, par une
     * route anonyme sans compte utilisateur. Sans adresse dans le journal, on
     * saurait qu'un envoi a eu lieu sans savoir vers où.
     */
    NotificationFacade::route('mail', 'promoteur@example.com')
        ->notify(new AlertNotification(
            ['type' => 'alert_fraud', 'title' => 'Écart caisse', 'message' => 'Anomalie', 'severity' => 'critique'],
            ['mail'],
        ));

    $log = NotificationLog::where('channel', 'mail')->first();

    expect($log)->not->toBeNull()
        ->and($log->recipient_email)->toBe('promoteur@example.com')
        ->and($log->user_id)->toBeNull();
});

test('l’écran d’historique affiche la ligne e-mail et son canal', function () {
    /*
     * Le journal ne sert que s'il se lit. L'écran ne montrait qu'un numéro de
     * téléphone : une ligne e-mail y serait apparue sans destinataire.
     */
    $this->adminUser->notify(new AlertNotification(
        ['type' => 'daily_summary', 'title' => 'Résumé Quotidien', 'message' => 'Effectif 1491'],
        ['mail'],
    ));

    $this->actingAs($this->adminUser)
        ->get(route('notifications.logs'))
        ->assertOk()
        ->assertSee($this->adminUser->email)
        ->assertSee('mail');
});
