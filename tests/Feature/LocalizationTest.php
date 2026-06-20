<?php

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('affiche les messages de validation en français par défaut', function () {
    expect(config('app.locale'))->toBe('fr');
    expect(__('validation.required', ['attribute' => 'email']))
        ->toBe('Le champ email est obligatoire.');
});

it('affiche les messages d\'authentification en français', function () {
    expect(__('auth.failed'))->toBe('Ces identifiants ne correspondent pas à nos enregistrements.');
});

it('permet à un utilisateur de choisir sa langue dans son profil', function () {
    $role = \App\Models\Role::firstOrCreate(
        ['name' => 'admin'],
        ['label' => 'Admin', 'display_name' => 'Admin', 'permissions' => ['L', 'C', 'M', 'S']]
    );
    $user = \App\Models\User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'en',
        ])
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->locale)->toBe('en');
});

it('refuse une langue non supportée', function () {
    $role = \App\Models\Role::firstOrCreate(
        ['name' => 'admin'],
        ['label' => 'Admin', 'display_name' => 'Admin', 'permissions' => ['L', 'C', 'M', 'S']]
    );
    $user = \App\Models\User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'de',
        ])
        ->assertSessionHasErrors('locale');
});

it('applique la langue de l\'utilisateur sur ses requêtes', function () {
    $role = \App\Models\Role::firstOrCreate(
        ['name' => 'admin'],
        ['label' => 'Admin', 'display_name' => 'Admin', 'permissions' => ['L', 'C', 'M', 'S']]
    );
    $user = \App\Models\User::factory()->create(['role_id' => $role->id, 'locale' => 'en']);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Profile Information');

    $user->update(['locale' => 'fr']);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Informations du profil');
});
