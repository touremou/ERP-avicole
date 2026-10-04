<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * DEVINER UN MOT DE PASSE EST BORNÉ, À CHAQUE PORTE QUI LE VÉRIFIE.
 *
 * La connexion était limitée (web : 5 essais par adresse et IP ; terrain :
 * 10 par minute). Quatre autres portes vérifient le MÊME mot de passe — changer
 * son mot de passe (web et terrain), le confirmer, supprimer son compte — et ne
 * l'étaient pas. Qui tenait une session ouverte, ou un téléphone déverrouillé,
 * pouvait le deviner là à volonté, puis s'en servir partout ailleurs.
 *
 * Six essais par minute, comme « mot de passe oublié » : le septième est refusé
 * (429) AVANT d'être examiné.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->compte = User::factory()->create(['password' => Hash::make('Secret123')]);
});

/** Six mauvais essais, puis le septième — qui doit être refusé sans examen. */
function septiemeEssai(callable $essai): int
{
    for ($i = 1; $i <= 6; $i++) {
        expect($essai()->status())->not->toBe(429, "essai {$i} déjà bloqué");
    }

    return $essai()->status();
}

test('terrain — changer son mot de passe', function () {
    Sanctum::actingAs($this->compte);

    expect(septiemeEssai(fn () => $this->patchJson('/api/v1/auth/password', [
        'current_password' => 'Devine0', 'password' => 'Nouveau123', 'password_confirmation' => 'Nouveau123',
    ])))->toBe(429);
});

test('bureau — changer son mot de passe', function () {
    $this->actingAs($this->compte);

    expect(septiemeEssai(fn () => $this->put(route('password.update'), [
        'current_password' => 'Devine0', 'password' => 'Nouveau123', 'password_confirmation' => 'Nouveau123',
    ])))->toBe(429);
});

test('bureau — confirmer son mot de passe', function () {
    $this->actingAs($this->compte);

    expect(septiemeEssai(fn () => $this->post('/confirm-password', ['password' => 'Devine0'])))->toBe(429);
});

test('bureau — supprimer son compte', function () {
    $this->actingAs($this->compte);

    expect(septiemeEssai(fn () => $this->delete(route('profile.destroy'), ['password' => 'Devine0'])))->toBe(429);

    expect(User::find($this->compte->id))->not->toBeNull();
});

test('un utilisateur honnête n’est pas gêné — non-régression', function () {
    $this->actingAs($this->compte);

    $this->put(route('password.update'), [
        'current_password' => 'Secret123', 'password' => 'Nouveau123', 'password_confirmation' => 'Nouveau123',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('Nouveau123', $this->compte->fresh()->password))->toBeTrue();
});
