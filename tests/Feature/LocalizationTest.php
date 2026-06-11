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
