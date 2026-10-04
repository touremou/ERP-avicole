<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SEULE RÈGLE DE MOT DE PASSE, À CHAQUE PORTE.
 *
 * L'application terrain exigeait lettres ET chiffres ; le web appelait
 * `Password::defaults()` sans l'avoir réglé (8 caractères, rien d'autre). Au
 * bureau, un utilisateur pouvait donc choisir « password » — le mot de passe
 * public des comptes de démonstration — que le téléphone lui aurait refusé.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->compte = User::factory()->create(['password' => Hash::make('Ancien123')]);
});

test('au bureau, « password » est refusé — comme au terrain', function () {
    $this->actingAs($this->compte)
        ->from(route('profile.edit'))
        ->put(route('password.update'), [
            'current_password' => 'Ancien123', 'password' => 'password', 'password_confirmation' => 'password',
        ])
        ->assertSessionHasErrorsIn('updatePassword', 'password');

    expect(Hash::check('Ancien123', $this->compte->fresh()->password))->toBeTrue();
});

test('au bureau, un mot de passe avec lettres et chiffres passe — non-régression', function () {
    $this->actingAs($this->compte)
        ->from(route('profile.edit'))
        ->put(route('password.update'), [
            'current_password' => 'Ancien123', 'password' => 'Poulailler7', 'password_confirmation' => 'Poulailler7',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('Poulailler7', $this->compte->fresh()->password))->toBeTrue();
});

test('au terrain, la même règle — elle n’est plus écrite à part', function () {
    Sanctum::actingAs($this->compte);

    $this->patchJson('/api/v1/auth/password', [
        'current_password' => 'Ancien123', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $source = file_get_contents(app_path('Http/Controllers/Api/AuthController.php'));
    expect(str_contains($source, 'Password::min('))->toBeFalse('le terrain redéclare sa propre règle');
});

test('l’assistant d’installation lit la même règle', function () {
    $source = file_get_contents(app_path('Http/Controllers/InstallController.php'));

    expect(str_contains($source, "'admin_password' => ['required', 'string', 'min:8'"))->toBeFalse()
        ->and(str_contains($source, 'Password::defaults()'))->toBeTrue();
});
