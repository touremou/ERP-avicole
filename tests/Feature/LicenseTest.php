<?php

use App\Models\License;
use App\Services\LicenseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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

test('un module hors licence est refusé à l\'admin lui-même (verrou commercial)', function () {
    // Licence incluant elevage mais PAS commerce.
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private'], ['modules' => ['elevage']]));

    expect(Gate::forUser($this->adminUser)->allows('elevage.L'))->toBeTrue()
        ->and(Gate::forUser($this->adminUser)->denies('commerce.L'))->toBeTrue()  // bloqué malgré admin
        ->and(Gate::forUser($this->adminUser)->denies('commerce.S'))->toBeTrue();
});

test('le lanceur masque les modules hors licence', function () {
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private'], ['modules' => ['elevage']]));

    $slugs = $this->adminUser->getAccessibleModules()->pluck('slug');
    expect($slugs->contains('commerce'))->toBeFalse()   // hors licence → masqué
        ->and($slugs->contains('production'))->toBeFalse()
        ->and($slugs->contains('elevage'))->toBeTrue()   // inclus → visible
        ->and($slugs->contains('admin'))->toBeTrue();    // infrastructure → toujours visible
});

test('système inactif : aucun module n\'est verrouillé (bypass admin normal)', function () {
    config()->set('license.public_key', ''); // désarme

    expect(Gate::forUser($this->adminUser)->allows('commerce.L'))->toBeTrue()
        ->and(Gate::forUser($this->adminUser)->allows('elevage.S'))->toBeTrue();
});

test('la limite d\'utilisateurs du plan est appliquée à la création', function () {
    // Plan limité à max_users = (effectif courant) → plus aucune création possible.
    $current = \App\Models\User::count();
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private'], ['max_users' => $current]));

    $this->actingAs($this->adminUser)
        ->post(route('users.store'), [
            'name' => 'Nouveau', 'email' => 'nouveau@ferme.gn',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role_id' => $this->adminUser->role_id,
        ])
        ->assertSessionHas('error');

    expect(\App\Models\User::where('email', 'nouveau@ferme.gn')->exists())->toBeFalse();
});

test('limite illimitée (0) : la création reste possible', function () {
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private'], ['max_users' => 0]));

    expect(app(LicenseService::class)->allowsMore('max_users', 9999))->toBeTrue()
        ->and(app(LicenseService::class)->limit('max_users'))->toBe(PHP_INT_MAX);
});

test('un module hors licence renvoie vers l\'écran d\'abonnement avec message dédié', function () {
    app(LicenseService::class)->activate('BIOCREST', makeCode($this->keys['private'], ['modules' => ['elevage']]));

    // commerce hors licence → la route est refusée puis redirigée vers l'activation.
    $this->actingAs($this->adminUser)
        ->get(route('clients.index'))
        ->assertRedirect(route('license.edit'));
});

test('un admin peut activer une licence via le formulaire', function () {
    $code = makeCode($this->keys['private']);

    $this->actingAs($this->adminUser)
        ->put(route('license.update'), ['identifiant' => 'BIOCREST', 'code' => $code])
        ->assertRedirect(route('license.edit'))
        ->assertSessionHas('success');

    expect(License::where('identifiant', 'BIOCREST')->exists())->toBeTrue();
});
