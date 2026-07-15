<?php

use function Pest\Laravel\getJson;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('expose une sonde de santé publique (sans auth)', function () {
    getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status'      => 'ok',
            'api_version' => 'v1',
            'database'    => 'up',
        ])
        ->assertJsonStructure(['status', 'app', 'api_version', 'database', 'server_time']);
});

it('renvoie les en-têtes CORS pour une origine autorisée (déploiement app.*)', function () {
    config(['cors.allowed_origins' => ['https://app.ferme.example.com']]);

    $this->get('/api/v1/health', ['Origin' => 'https://app.ferme.example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.ferme.example.com');
});

it('répond au préflight OPTIONS de la PWA', function () {
    config(['cors.allowed_origins' => ['https://app.ferme.example.com']]);

    $response = $this->call('OPTIONS', '/api/v1/sync/pull', [], [], [], [
        'HTTP_ORIGIN'                        => 'https://app.ferme.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->getStatusCode())->toBe(204);
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.ferme.example.com');
});
