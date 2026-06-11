<?php

uses(Tests\TestCase::class);

afterEach(function () {
    @unlink(storage_path('installed'));
});

it('affiche la page de prérequis de l\'assistant d\'installation', function () {
    $response = $this->get('/install');

    $response->assertOk();
    $response->assertSee('Vérification des prérequis');
    $response->assertSee('PHP &gt;= 8.3', false);
});

it('redirige l\'assistant vers la connexion une fois l\'application installée', function () {
    file_put_contents(storage_path('installed'), now()->toDateTimeString());

    $response = $this->get('/install');

    $response->assertRedirect('/login');
});

it('ne touche pas à la page de connexion une fois installée', function () {
    file_put_contents(storage_path('installed'), now()->toDateTimeString());

    $response = $this->get('/login');

    $response->assertOk();
});
