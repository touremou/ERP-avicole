<?php

use App\Models\License;
use App\Services\LicenseService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Cache::flush(); // évite la contamination de l'horloge anti-recul entre tests

    // Arme le système de licence avec une paire de clés éphémère.
    $this->keys = LicenseService::generateKeypair();
    config()->set('license.public_key', $this->keys['public']);
    config()->set('license.enforce', true);
    config()->set('license.grace_days', 7);
});

/** Forge un code de licence signé valide pour les tests. */
function makeCode(string $privateKey, array $overrides = []): string
{
    $now = now();
    $payload = array_merge([
        'v' => 1, 'id' => 'BIOCREST', 'client' => 'BioCrest', 'plan' => 'pro',
        'modules' => ['elevage', 'commerce'], 'max_users' => 10, 'max_farms' => 3,
        'sms_quota' => 1000, 'iat' => $now->getTimestamp(), 'nbf' => $now->getTimestamp(),
        'exp' => $now->copy()->addDays(366)->getTimestamp(),
    ], $overrides);

    return LicenseService::sign($payload, $privateKey);
}

test('un code signé par la bonne clé est vérifié, un code falsifié est rejeté', function () {
    $svc = app(LicenseService::class);
    $code = makeCode($this->keys['private']);

    $payload = $svc->verify($code);
    expect($payload['id'])->toBe('BIOCREST')->and($payload['plan'])->toBe('pro');

    // Falsification : on altère un caractère du payload.
    [$p, $s] = explode('.', $code);
    $tampered = substr($p, 0, -2) . 'XY' . '.' . $s;
    expect(fn () => $svc->verify($tampered))->toThrow(RuntimeException::class);

    // Code signé par une AUTRE clé privée → signature invalide.
    $other = LicenseService::generateKeypair();
    $foreign = makeCode($other['private']);
    expect(fn () => $svc->verify($foreign))->toThrow(RuntimeException::class);
});

test('l\'activation enregistre la licence et l\'identifiant doit correspondre', function () {
    $svc = app(LicenseService::class);
    $code = makeCode($this->keys['private']);

    // Identifiant erroné → refus.
    expect(fn () => $svc->activate('MAUVAIS', $code))->toThrow(RuntimeException::class);

    $license = $svc->activate('BIOCREST', $code);
    expect($license)->toBeInstanceOf(License::class)
        ->and($license->plan)->toBe('pro')
        ->and($svc->status())->toBe(LicenseService::STATUS_ACTIVE);
});

test('le statut passe par grâce puis expiré selon l\'échéance', function () {
    $svc = app(LicenseService::class);

    // Échéance il y a 3 jours, grâce 7 → période de grâce.
    $svc->activate('BIOCREST', makeCode($this->keys['private'], ['exp' => now()->subDays(3)->getTimestamp()]));
    expect($svc->status())->toBe(LicenseService::STATUS_GRACE)
        ->and($svc->shouldBlock())->toBeFalse();

    // Échéance il y a 10 jours → hors grâce → expiré et bloquant.
    License::query()->delete();
    $svc->activate('BIOCREST', makeCode($this->keys['private'], ['exp' => now()->subDays(10)->getTimestamp()]));
    expect($svc->status())->toBe(LicenseService::STATUS_EXPIRED)
        ->and($svc->shouldBlock())->toBeTrue();
});

test('le recul d\'horloge ne fait pas rajeunir une licence expirée', function () {
    $svc = app(LicenseService::class);
    $svc->activate('BIOCREST', makeCode($this->keys['private']));

    // On mémorise « maintenant + 400 jours » comme dernier instant vu.
    $future = now()->addDays(400)->getTimestamp();
    Cache::forever('license.last_seen_ts', $future);

    // Même si l'horloge système est revenue à aujourd'hui, l'instant de confiance
    // reste dans le futur → la licence (366 j) est considérée comme expirée.
    expect($svc->trustedNow()->getTimestamp())->toBe($future)
        ->and($svc->status())->toBe(LicenseService::STATUS_EXPIRED);
});

test('le quota SMS se décompte et bloque à zéro', function () {
    $svc = app(LicenseService::class);
    $svc->activate('BIOCREST', makeCode($this->keys['private'], ['sms_quota' => 2]));

    expect($svc->smsRemaining())->toBe(2)
        ->and($svc->consumeSms())->toBeTrue()
        ->and($svc->consumeSms())->toBeTrue()
        ->and($svc->smsRemaining())->toBe(0)
        ->and($svc->consumeSms())->toBeFalse(); // épuisé
});

test('le déverrouillage des modules suit la licence', function () {
    $svc = app(LicenseService::class);
    $svc->activate('BIOCREST', makeCode($this->keys['private'], ['modules' => ['elevage']]));

    expect($svc->allowsModule('elevage'))->toBeTrue()
        ->and($svc->allowsModule('commerce'))->toBeFalse();

    // Plan « tous modules ».
    License::query()->delete();
    $svc->activate('BIOCREST', makeCode($this->keys['private'], ['modules' => ['*']]));
    expect($svc->allowsModule('commerce'))->toBeTrue();
});

test('système inactif (pas de clé) → aucun blocage, quota infini, modules ouverts', function () {
    config()->set('license.public_key', '');
    $svc = app(LicenseService::class);

    expect($svc->isEnabled())->toBeFalse()
        ->and($svc->shouldBlock())->toBeFalse()
        ->and($svc->consumeSms())->toBeTrue()
        ->and($svc->allowsModule('commerce'))->toBeTrue();
});

test('une instance bloquée redirige vers l\'écran d\'activation', function () {
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private'], ['exp' => now()->subDays(30)->getTimestamp()]));

    $this->actingAs($this->adminUser)
        ->get(route('dashboard'))
        ->assertRedirect(route('license.edit'));

    // L'écran d'activation reste accessible (pas de boucle).
    $this->actingAs($this->adminUser)->get(route('license.edit'))->assertOk();
});

test('une licence active laisse passer et l\'écran montre l\'état', function () {
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private']));

    $this->actingAs($this->adminUser)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->adminUser)->get(route('license.edit'))
        ->assertOk()->assertSee('BioCrest');
});

test('un admin peut activer une licence via le formulaire', function () {
    $code = makeCode($this->keys['private']);

    $this->actingAs($this->adminUser)
        ->put(route('license.update'), ['identifiant' => 'BIOCREST', 'code' => $code])
        ->assertRedirect(route('license.edit'))
        ->assertSessionHas('success');

    expect(License::where('identifiant', 'BIOCREST')->exists())->toBeTrue();
});
