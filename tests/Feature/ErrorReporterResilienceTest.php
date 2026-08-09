<?php

use App\Services\ErrorAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEUX DÉFAUTS SUR LE CHEMIN DES ALERTES D'ERREUR.
 *
 * 1. LE RAPPORTEUR ÉCHOUAIT EN RAPPORTANT. Le throttle mémorisait un objet Carbon
 *    SÉRIALISÉ dans le cache. À la relecture, quand la classe n'est pas
 *    reconstructible — ce qui arrive précisément pendant une erreur fatale — PHP
 *    rend un `__PHP_Incomplete_Class`, et `now()->diffInMinutes()` lève une
 *    TypeError.
 *
 *    VU EN PRODUCTION, journal du 14/07 : erreur fatale à 07:51:51
 *    (CheckHaccpRegisters::alert() déclarée privée), puis TypeError du rapporteur
 *    à 07:51:52. L'alerte de l'erreur fatale n'est jamais partie, et le journal a
 *    gardé la trace du messager au lieu du message.
 *
 *    On mémorise désormais un horodatage entier — aucun objet, donc aucune
 *    désérialisation — et la méthode entière est enveloppée : un rapporteur
 *    d'erreur ne doit JAMAIS échouer, quelle qu'en soit la cause.
 *
 * 2. LE COUPE-CIRCUIT NE COUPAIT RIEN. `ERROR_ALERTS_ENABLED` était lu par un
 *    env() posé hors de config/. Le déploiement met la configuration en cache,
 *    après quoi Laravel ne lit plus le .env du tout. Le réglage documenté dans le
 *    runbook n'avait donc aucun effet en production. Il est désormais déclaré dans
 *    config/, donc capturé par config:cache.
 */

test('une valeur de cache illisible ne fait PAS échouer le rapporteur', function () {
    // Reproduction exacte du 14/07 : le cache rend un objet que PHP n'a pas su
    // reconstruire. Avant, cette ligne levait une TypeError DANS le gestionnaire
    // d'exceptions.
    $e = new RuntimeException('Panne simulée');
    $key = 'error_alert_' . md5($e->getFile() . $e->getLine());

    Cache::put($key, unserialize('O:8:"NoClass1":0:{}'), 600);

    expect(fn () => ErrorAlertService::handle($e))->not->toThrow(Throwable::class);
});

test('le throttle mémorise un horodatage, jamais un objet', function () {
    // La cause racine : c'est la sérialisation d'un objet qui a produit la panne.
    $source = file_get_contents(app_path('Services/ErrorAlertService.php'));

    expect($source)->toContain("cache()->put(\$errorKey, time(),")
        ->and($source)->not->toContain('cache()->put($errorKey, now(),');
});

test('le rapporteur ne relaie JAMAIS une exception, quelle qu’elle soit', function () {
    // Le garde-fou général. Le cache lui-même peut tomber — c'est même le cas le
    // plus probable pendant un incident : base injoignable, disque plein.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Cache injoignable'));
    Cache::shouldReceive('put')->andReturn(true);

    expect(fn () => ErrorAlertService::handle(new RuntimeException('Panne')))
        ->not->toThrow(Throwable::class);
});

test('le coupe-circuit est lu depuis la CONFIG, pas depuis env()', function () {
    // Un env() hors de config/ est inerte dès que la configuration est en cache —
    // c'est-à-dire en production. Le réglage documenté ne coupait rien.
    $source = file_get_contents(app_path('Services/ErrorAlertService.php'));

    expect($source)->toContain("config('whatsapp.error_alerts_enabled'")
        ->and($source)->not->toContain("env('ERROR_ALERTS_ENABLED'");

    // Et la clef existe réellement, sinon on aurait déplacé le défaut.
    expect(config('whatsapp.error_alerts_enabled'))->not->toBeNull();
});

test('aucun service applicatif ne lit env() hors de config/', function () {
    // La famille entière : toute lecture d'env() en dehors de config/ est morte
    // en production dès que la configuration est mise en cache — silencieusement.
    //
    // UNE exception, et une seule : l'INSTALLEUR. Il écrit le .env et doit donc en
    // lire les valeurs BRUTES — c'est tout son objet. Il s'exécute d'ailleurs
    // avant qu'aucune configuration ne soit mise en cache, sur une instance qui
    // n'est pas encore installée. L'exclure n'est pas une commodité : lire la
    // config à sa place lui rendrait des valeurs périmées dans le même processus.
    $allowed = ['app/Http/Controllers/InstallController.php'];

    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $no => $line) {
            if (str_starts_with(ltrim($line), '*') || str_starts_with(ltrim($line), '//')) {
                continue;
            }

            if (preg_match('/(?<![a-zA-Z_>])env\(/', $line)) {
                $relative = str_replace(base_path() . '/', '', $file->getPathname());

                if (in_array($relative, $allowed, true)) {
                    continue;
                }

                $offenders[] = $relative . ':' . ($no + 1);
            }
        }
    }

    expect($offenders)->toBe([], "Lectures d'env() hors de config/ — inertes en production :\n  " . implode("\n  ", $offenders));
});

test('l’endpoint des types de production s’ouvre à l’élevage, pas à l’administration', function () {
    // Rangé dans le groupe d'administration, son droit résolu était `admin.S` : un
    // chef de site avec elevage.C recevait un 403 en choisissant une espèce non
    // volaille, et l'écran avalait le refus — sélecteur vide, champ obligatoire,
    // bande impossible à créer, sans un mot d'explication.
    $route = collect(Route::getRoutes())
        ->first(fn ($r) => $r->getName() === 'api.species.production-types');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('can:elevage.L')
        ->and($route->gatherMiddleware())->not->toContain('can:S');
});

test('l’écran « Nouvelle bande » ne dépend plus du réseau pour ses types', function () {
    // Le vrai correctif : la donnée est DÉJÀ chargée par le contrôleur. L'appel
    // réseau était redondant — et c'est cette redondance qui a permis à deux
    // droits de diverger.
    $view = file_get_contents(resource_path('views/batches/create.blade.php'));

    expect($view)->not->toContain('fetch(`/api/species/')
        ->and($view)->toContain('PRODUCTION_TYPES_BY_SPECIES');
});
