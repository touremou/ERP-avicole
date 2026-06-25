<?php

uses(Tests\TestCase::class);

// Le marqueur storage/installed est un fichier disque partagé : un run précédent
// interrompu (OOM, Ctrl-C) pouvait le laisser traîner et faire échouer le 1er test.
// On garantit un état propre AVANT et APRÈS chaque test (idempotence).
beforeEach(function () {
    @unlink(storage_path('installed'));
});

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
