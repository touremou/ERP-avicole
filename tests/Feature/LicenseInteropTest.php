<?php

use App\Services\LicenseService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/**
 * Interopérabilité SERVEUR FOURNISSEUR ↔ ERP CLIENT.
 *
 * Garantit en CI que les codes émis par license-server/ (LicenseServer\
 * LicenseAuthority) sont acceptés tels quels par l'ERP (App\Services\
 * LicenseService), et réciproquement — le format de jeton (payload.signature
 * Ed25519) ne doit JAMAIS diverger entre les deux applications.
 */
beforeEach(function () {
    $this->setUpRbac();
    Cache::flush();

    // Le serveur fournisseur n'est pas autoloadé par composer (appli séparée) :
    // on le charge directement depuis le dépôt.
    require_once base_path('license-server/src/LicenseAuthority.php');
});

test('un code émis par le serveur fournisseur est vérifié et activé par l\'ERP', function () {
    $pair      = \LicenseServer\LicenseAuthority::generateKeypair();
    $authority = new \LicenseServer\LicenseAuthority($pair['private']);

    // Le serveur construit le payload depuis le catalogue de plans de l'ERP
    // (les deux catalogues doivent rester alignés).
    $payload = $authority->buildPayload(config('license.plans'), [
        'id' => 'INTEROP', 'client' => 'Client Interop', 'plan' => 'pro', 'days' => 120,
    ]);
    $code = $authority->sign($payload);

    // L'ERP embarque la clé publique correspondante et vérifie hors-ligne.
    config()->set('license.public_key', $pair['public']);
    config()->set('license.enforce', true);

    $verified = app(LicenseService::class)->verify($code);
    expect($verified['id'])->toBe('INTEROP')->and($verified['plan'])->toBe('pro');

    // Activation complète côté ERP.
    $license = app(LicenseService::class)->activate('INTEROP', $code);
    expect(app(LicenseService::class)->status())->toBe(LicenseService::STATUS_ACTIVE)
        ->and(app(LicenseService::class)->allowsModule('commerce'))->toBeTrue()
        ->and($license->client_name)->toBe('Client Interop');
});

test('la clé publique dérivée par le serveur correspond à celle attendue par l\'ERP', function () {
    $pair      = \LicenseServer\LicenseAuthority::generateKeypair();
    $authority = new \LicenseServer\LicenseAuthority($pair['private']);

    // publicKey() (dérivée de la privée) doit égaler la publique de la paire.
    expect($authority->publicKey())->toBe($pair['public']);
});

test('un code signé par l\'ERP est vérifié par le serveur fournisseur (réciprocité)', function () {
    // L'ERP signe (ex. commande de dépannage license:issue) ...
    $erpPair = LicenseService::generateKeypair();
    $payload = [
        'v' => 1, 'id' => 'RECIPRO', 'client' => 'Récipro', 'plan' => 'basic',
        'modules' => ['elevage'], 'max_users' => 3, 'max_farms' => 1, 'sms_quota' => 200,
        'iat' => now()->getTimestamp(), 'nbf' => now()->getTimestamp(),
        'exp' => now()->addDays(30)->getTimestamp(),
    ];
    $code = LicenseService::sign($payload, $erpPair['private']);

    // ... et le serveur fournisseur le vérifie avec la même clé privée.
    $authority = new \LicenseServer\LicenseAuthority($erpPair['private']);
    $decoded   = $authority->verify($code);

    expect($decoded['id'])->toBe('RECIPRO')->and($decoded['modules'])->toBe(['elevage']);
});
