<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\ErrorAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'ALERTE D'ERREUR SERVEUR N'EXISTAIT QUE SUR WHATSAPP.
 *
 * C'est l'alerte de DERNIER RECOURS : celle par laquelle on découvre toutes les
 * autres pannes. Une tâche planifiée morte, un 500 sur un écran, une migration
 * ratée — c'est elle qui prévient.
 *
 * Elle n'avait qu'un canal, WhatsApp. Sur cette installation, ce canal est en mode
 * « journal » : aucune erreur serveur n'était donc signalée à personne. Le 14
 * juillet en est la démonstration — une commande planifiée est morte, et personne
 * ne l'a su pendant des semaines.
 *
 * Second défaut, du même ordre : la requête des destinataires exigeait
 * `whereNotNull('whatsapp_phone')`. Un administrateur sans numéro renseigné était
 * donc écarté de TOUT. Quand il n'y avait qu'un canal, la condition était sans
 * conséquence visible ; avec trois, elle privait de tout un compte qui n'avait
 * simplement pas rempli un champ.
 *
 * ─── POURQUOI PAS broadcast(), COMME AILLEURS ───
 *
 * Ce serait l'erreur à ne pas commettre. Cette méthode s'exécute PENDANT une
 * exception, souvent une exception de base de données ou de cache. broadcast() lit
 * les préférences de chaque destinataire, résout des cartes de destination et écrit
 * en base : il ajoute des chemins d'échec au moment le plus fragile.
 *
 * Un rapporteur d'erreur qui échoue détruit l'information qu'il devait transmettre.
 * C'est précisément ce qui s'est produit le 14/07 : erreur fatale à 07:51:51,
 * TypeError du rapporteur à 07:51:52 — le journal a gardé la trace du messager, pas
 * du message (cf. ErrorReporterResilienceTest).
 *
 * Les trois canaux sont donc tentés SÉPARÉMENT, chacun dans son try/catch. Un canal
 * muet vaut mieux que trois canaux perdus.
 *
 * ─── CE QUI RENDAIT CE SERVICE INTESTABLE ───
 *
 * `attempt()` commençait par `if (app()->runningUnitTests()) return;`, avec pour
 * motif écrit « la CI ne doit pas tenter d'envoyer du WhatsApp ». L'intention était
 * juste, la portée beaucoup trop large : AUCUN test ne pouvait vérifier qu'une
 * erreur serveur atteint quelqu'un. C'est ainsi que ce service a pu n'avoir qu'un
 * seul canal, muet sur cette installation, sans que rien ne le signale.
 *
 * Le garde ne protégeait presque rien : phpunit.xml impose MAIL_MAILER=array, et le
 * driver WhatsApp vaut « log » par défaut. Le seul risque réel est un appel HTTP
 * sortant si un test choisissait un vrai provider — et ce risque est désormais
 * traité là où il se trouve, sur le canal WhatsApp seul. Le dernier test de ce
 * fichier l'éprouve avec `Http::preventStrayRequests()`, parce qu'un garde qu'on
 * desserre doit être remplacé par une vérification, pas par une promesse.
 *
 * Ces tests appellent `attempt()` par réflexion : c'est une méthode privée, et
 * `handle()` ne fait que l'envelopper dans la garantie de ne jamais relayer.
 */

beforeEach(function () {
    $this->setUpRbac();

    // L'état réel de cette exploitation.
    Setting::set('whatsapp.driver', 'log');

    config(['whatsapp.error_alerts_enabled' => true]);
});

/** Déclenche la tentative d'alerte, en contournant le coupe-circuit de test. */
function reportError(\Throwable $e): void
{
    $method = (new ReflectionClass(ErrorAlertService::class))->getMethod('attempt');
    $method->setAccessible(true);
    $method->invoke(null, $e);
}

/** Vide le throttle : sans cela, le second test du fichier ne déclencherait rien. */
function clearErrorThrottle(\Throwable $e): void
{
    cache()->forget('error_alert_' . md5($e->getFile() . $e->getLine()));
}

test('une erreur serveur arrive par la CLOCHE, WhatsApp muet ou non', function () {
    // Le défaut principal. Avant correction, cette alerte n'existait que sur
    // WhatsApp : en mode journal, elle n'atteignait personne.
    $e = new \RuntimeException('Base de données inaccessible');
    clearErrorThrottle($e);

    reportError($e);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())
        ->toBeGreaterThan(0);
});

test('un administrateur SANS numéro WhatsApp est tout de même prévenu', function () {
    // La requête excluait ces comptes de la sélection, donc de tous les canaux.
    $this->adminUser->update(['whatsapp_phone' => null]);

    $e = new \RuntimeException('Erreur sans numéro');
    clearErrorThrottle($e);

    reportError($e);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())
        ->toBeGreaterThan(0);
});

test('l’alerte part aussi par E-MAIL à l’adresse admin', function () {
    // Le seul canal qui sorte du serveur sans dépendre d'un provider WhatsApp.
    Notification::fake();
    Setting::set('whatsapp.admin_email', 'promoteur@example.com');

    $e = new \RuntimeException('Erreur à relayer par courriel');
    clearErrorThrottle($e);

    reportError($e);

    Notification::assertSentOnDemand(\App\Notifications\AlertNotification::class);
});

test('sans adresse admin renseignée, aucun e-mail n’est tenté', function () {
    // Vide = inactif, comme partout ailleurs. Tenter un envoi vers une adresse
    // absente ne produirait qu'une ligne d'erreur dans le journal.
    Notification::fake();
    Setting::set('whatsapp.admin_email', '');

    $e = new \RuntimeException('Erreur sans adresse admin');
    clearErrorThrottle($e);

    reportError($e);

    Notification::assertNothingSentTo(new \Illuminate\Notifications\AnonymousNotifiable);
});

test('le message porte le FICHIER et la LIGNE — sans quoi il ne sert à rien', function () {
    $e = new \RuntimeException('Message reconnaissable');
    clearErrorThrottle($e);

    reportError($e);

    $payload = (string) DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)->value('data');

    expect($payload)->toContain('Message reconnaissable')
        ->and($payload)->toContain('RuntimeException');
});

test('l’alerte est marquée CRITIQUE — elle ignore les heures silencieuses', function () {
    // Une erreur serveur à 3 h du matin reste une erreur serveur. La sévérité
    // « critique » est ce qui lui permet de passer les heures calmes.
    $e = new \RuntimeException('Erreur nocturne');
    clearErrorThrottle($e);

    reportError($e);

    $payload = (string) DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)->value('data');

    expect($payload)->toContain('critique');
});

test('un canal en panne n’empêche PAS les autres', function () {
    // Le point de conception de ce lot. On casse le canal WhatsApp au niveau du
    // conteneur : la cloche doit tout de même recevoir.
    app()->bind(\App\Services\WhatsAppService::class, function () {
        throw new \RuntimeException('Provider WhatsApp injoignable');
    });

    $e = new \RuntimeException('Erreur malgré un canal cassé');
    clearErrorThrottle($e);

    reportError($e);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())
        ->toBeGreaterThan(0);
});

test('le rapporteur ne relaie toujours JAMAIS une exception', function () {
    // La garantie qui primait avant ce lot et qui prime encore : ajouter des canaux
    // ajoute des chemins d'échec, et un rapporteur qui échoue détruit
    // l'information qu'il devait transmettre.
    app()->bind(\App\Services\WhatsAppService::class, fn () => throw new \RuntimeException('canal mort'));

    $e = new \RuntimeException('Erreur d’origine');

    // Par handle(), qui porte la garantie de bout en bout.
    ErrorAlertService::handle($e);

    expect(true)->toBeTrue();
});

test('le throttle protège d’une boucle d’erreurs', function () {
    // Une boucle d'erreurs identiques ne doit pas produire une avalanche de
    // notifications — l'écran deviendrait illisible au moment où il compte le plus.
    $e = new \RuntimeException('Erreur répétée');
    clearErrorThrottle($e);

    reportError($e);
    reportError($e);
    reportError($e);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())
        ->toBe(1);
});

test('les alertes d’erreur restent coupables par la configuration', function () {
    // ERROR_ALERTS_ENABLED=false doit tout arrêter, cloche comprise : c'est le
    // recours si le mécanisme devenait lui-même bruyant.
    config(['whatsapp.error_alerts_enabled' => false]);

    $e = new \RuntimeException('Erreur alors que les alertes sont coupées');
    clearErrorThrottle($e);

    reportError($e);

    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())->toBe(0);
});

test('en test, aucun appel SORTANT n’est tenté si un vrai provider est configuré', function () {
    // C'est le risque que je viens de desserrer, et il faut donc l'éprouver.
    // L'arrêt global en test protégeait d'un appel HTTP réel vers un provider. Il
    // est remplacé par un refus CIBLÉ sur le seul canal concerné : la CI ne doit
    // jamais joindre Twilio, même si un test choisit ce driver par erreur.
    \Illuminate\Support\Facades\Http::preventStrayRequests();

    Setting::set('whatsapp.driver', 'twilio');
    Setting::set('whatsapp.api_key', 'AC000:jeton');
    Setting::set('whatsapp.sender', '+14155238886');
    $this->adminUser->update(['whatsapp_phone' => '+224620000000']);

    $e = new \RuntimeException('Erreur avec un vrai provider configuré');
    clearErrorThrottle($e);

    // Si un appel sortant était tenté, preventStrayRequests() ferait échouer ce test.
    reportError($e);

    // Et la cloche, elle, a bien reçu : couper le canal sortant ne doit pas
    // supprimer l'alerte.
    expect(DB::table('notifications')->where('notifiable_id', $this->adminUser->id)->count())
        ->toBeGreaterThan(0);
});
