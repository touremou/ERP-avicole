<?php

use App\Models\Setting;
use App\Support\MailSettings;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * L'E-MAIL SE RÈGLE DANS L'APPLICATION, plus dans le .env.
 *
 * Demandé par le promoteur : « sur notifications/preferences, on ne peut que
 * renseigner le numéro WhatsApp. On peut de la même manière permettre la
 * configuration du mail au lieu de le faire dans .env ».
 *
 * WhatsApp et SMS se réglaient déjà à l'écran ; l'e-mail exigeait un accès SSH et
 * l'édition d'un fichier caché. Le promoteur vit à l'étranger : un réglage qui
 * demande un terminal est un réglage qu'il ne peut pas corriger le jour où il
 * échoue — et c'est exactement ce qui venait d'arriver.
 *
 * DEUX PARTIS PRIS.
 *
 * 1. PAS de réglage « chiffrement ». Il se DÉDUIT du port : c'est une conséquence,
 *    pas une décision indépendante. En offrir un rouvrirait le défaut signalé —
 *    port 465 avec un schéma incohérent, authentification refusée, et un message
 *    d'erreur qui accuse le mot de passe.
 *
 * 2. Le .env reste le REPLI. Sans hôte saisi, rien ne change : une mise à jour ne
 *    doit pas modifier le comportement d'un serveur déjà configuré.
 */

beforeEach(function () {
    $this->setUpRbac();
    Setting::clearCache();
});

test('les réglages e-mail existent et le mot de passe est traité comme un secret', function () {
    $rows = Setting::where('group', 'mail')->whereNull('farm_id')->get()->keyBy('key');

    expect($rows->keys()->all())
        ->toEqualCanonicalizing(['mailer', 'host', 'port', 'username', 'password', 'from_address']);

    // Même traitement que la clef API WhatsApp : jamais réaffiché, un champ vide
    // conserve la valeur existante (cf. SettingsController).
    expect($rows['password']->type)->toBe('password')
        ->and($rows['password']->is_sensitive)->toBeTrue();
});

test('AUCUN réglage de chiffrement n’est proposé — il se déduit du port', function () {
    // Le proposer rouvrirait le défaut : deux façons de se contredire.
    expect(Setting::where('group', 'mail')->where('key', 'scheme')->exists())->toBeFalse()
        ->and(Setting::where('group', 'mail')->where('key', 'encryption')->exists())->toBeFalse();
});

test('la règle du chiffrement est déclarée UNE fois', function () {
    // config/mail.php (chemin .env) et la surcharge par les Réglages doivent
    // s'accorder : deux copies feraient afficher un réglage que l'envoi
    // n'applique pas.
    $config = file_get_contents(base_path('config/mail.php'));

    expect($config)->toContain('MailSettings::schemeForPort(')
        ->and(MailSettings::schemeForPort(null, 465))->toBe('smtps')
        ->and(MailSettings::schemeForPort(null, 587))->toBeNull()
        // Un serveur non standard reste configurable.
        ->and(MailSettings::schemeForPort('smtp', 465))->toBe('smtp');
});

test('sans hôte saisi, le .env continue de faire foi', function () {
    // Une mise à jour ne doit pas changer le comportement d'un serveur en service.
    config(['mail.mailers.smtp.host' => 'serveur-du-env.example', 'mail.default' => 'smtp']);

    MailSettings::apply();

    expect(config('mail.mailers.smtp.host'))->toBe('serveur-du-env.example')
        ->and(MailSettings::configured())->toBeFalse();
});

test('les réglages saisis pilotent réellement l’envoi', function () {
    Setting::set('mail.host', 'mail.biocrest.fr');
    Setting::set('mail.port', '465');
    Setting::set('mail.username', 'contact@biocrest.fr');
    Setting::set('mail.password', 'secret');
    Setting::clearCache();

    MailSettings::apply();

    expect(config('mail.mailers.smtp.host'))->toBe('mail.biocrest.fr')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        // Le port 465 impose le chiffrement implicite, sans qu'on l'ait demandé.
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.mailers.smtp.username'))->toBe('contact@biocrest.fr');
});

test('sans expéditeur saisi, l’identifiant fait office d’expéditeur', function () {
    // La plupart des hébergements mutualisés refusent un expéditeur différent du
    // compte authentifié. Retenir une adresse générique par défaut ferait échouer
    // l'envoi sans dire pourquoi — c'est le défaut qu'on vient de corriger.
    Setting::set('mail.host', 'mail.biocrest.fr');
    Setting::set('mail.username', 'contact@biocrest.fr');
    Setting::clearCache();

    MailSettings::apply();

    expect(config('mail.from.address'))->toBe('contact@biocrest.fr');
});

test('un expéditeur explicitement saisi est respecté', function () {
    // Certains serveurs l'autorisent : on ne l'impose pas, on le signale.
    Setting::set('mail.host', 'mail.biocrest.fr');
    Setting::set('mail.username', 'contact@biocrest.fr');
    Setting::set('mail.from_address', 'ne-pas-repondre@biocrest.fr');
    Setting::clearCache();

    MailSettings::apply();

    expect(config('mail.from.address'))->toBe('ne-pas-repondre@biocrest.fr');
});

test('le nom d’expéditeur suit l’identité de l’exploitation', function () {
    // Une seule déclaration du nom (cf. Setting::companyName) : c'est ce qui
    // signait « AviSmart » sur les téléphones alors que les Réglages disaient
    // autre chose.
    Setting::set('general.company_name', 'Biocrest SARL');
    Setting::set('mail.host', 'mail.biocrest.fr');
    Setting::clearCache();

    MailSettings::apply();

    expect(config('mail.from.name'))->toBe('Biocrest SARL');
});

test('le canal « log » est honoré : rien ne part vraiment', function () {
    // Utile pour vérifier un contenu, trompeur si on l'oublie — d'où le bandeau.
    Setting::set('mail.host', 'mail.biocrest.fr');
    Setting::set('mail.mailer', 'log');
    Setting::clearCache();

    MailSettings::apply();

    expect(config('mail.default'))->toBe('log');
});

test('l’écran de préférences DIT l’état du canal e-mail', function () {
    // La page ne parlait que du numéro WhatsApp : le bouton « E-mail » envoyait
    // un test sans qu'on sache avec quelle configuration, ni où la corriger.
    config(['mail.default' => 'log']);

    $this->actingAs($this->adminUser)
        ->get(route('notifications.preferences'))
        ->assertOk()
        ->assertSee(e(__('Canal e-mail en mode journal : les messages sont écrits dans le journal, pas envoyés.')), false)
        // …et mène au bon écran de réglage.
        ->assertSee('group=mail', false);
});

test('l’écran AVERTIT quand l’expéditeur diffère du compte authentifié', function () {
    // Dire avant le test plutôt qu'après l'échec : le message brut du serveur
    // parle d'identifiants et oriente vers la mauvaise cause.
    config([
        'mail.default' => 'smtp',
        'mail.from.address' => 'no-reply@biocrest.fr',
        'mail.mailers.smtp.username' => 's.avismart@biocrest.fr',
    ]);

    $this->actingAs($this->adminUser)
        ->get(route('notifications.preferences'))
        ->assertOk()
        ->assertSee(e(__("diffère du compte authentifié")), false);
});

test('le groupe e-mail apparaît dans les Réglages', function () {
    expect(Setting::getGroups())->toHaveKey('mail');

    $this->actingAs($this->adminUser)
        ->get(route('settings.index', ['group' => 'mail']))
        ->assertOk()
        ->assertSee(e(__('Serveur SMTP')), false);
});
