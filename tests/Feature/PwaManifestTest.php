<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('sert un manifest PWA valide sans authentification', function () {
    $response = $this->get('/manifest.webmanifest');

    $response->assertOk();
    $response->assertJson([
        'display' => 'standalone',
        'theme_color' => '#349937',
    ]);

    expect($response->json('icons'))->not->toBeEmpty()
        ->and($response->json('name'))->not->toBeEmpty();
});

it('reflète le nom de l\'entreprise des paramètres dans le manifest', function () {
    \App\Models\Setting::set('general.company_name', 'Ferme Test');

    $response = $this->get('/manifest.webmanifest');

    $response->assertOk();
    $response->assertJsonPath('name', 'Ferme Test');
});
