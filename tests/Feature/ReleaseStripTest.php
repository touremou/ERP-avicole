<?php

use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->dir = storage_path('framework/testing/release-' . uniqid());
    File::ensureDirectoryExists($this->dir . '/app');
    File::ensureDirectoryExists($this->dir . '/resources/views'); // ne doit PAS être touché
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('release:strip refuse l\'arborescence de travail', function () {
    $this->artisan('release:strip', ['path' => base_path()])
        ->expectsOutputToContain('Refus')
        ->assertExitCode(1);
});

test('release:strip retire les commentaires du PHP applicatif sans casser le code', function () {
    $php = <<<'PHP'
    <?php
    // Ceci est un commentaire secret
    /* bloc confidentiel */
    function aviSmartSomme(int $a, int $b): int {
        return $a + $b; // addition
    }
    PHP;
    File::put($this->dir . '/app/Demo.php', $php);

    // Un fichier hors des dossiers cibles ne doit pas être modifié.
    $blade = "{{-- commentaire blade --}}\n<div>Bonjour</div>";
    File::put($this->dir . '/resources/views/demo.blade.php', $blade);

    $this->artisan('release:strip', ['path' => $this->dir])
        ->expectsOutputToContain('Durcissement terminé')
        ->assertExitCode(0);

    $stripped = File::get($this->dir . '/app/Demo.php');

    expect($stripped)->not->toContain('commentaire secret')
        ->and($stripped)->not->toContain('bloc confidentiel')
        ->and($stripped)->not->toContain('// addition')
        ->and($stripped)->toContain('function aviSmartSomme'); // code préservé

    // La vue Blade (hors dossiers cibles) reste intacte.
    expect(File::get($this->dir . '/resources/views/demo.blade.php'))->toContain('commentaire blade');

    // Le PHP dépouillé reste syntaxiquement valide.
    File::put($f = $this->dir . '/app/Demo.php', $stripped);
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    expect($code)->toBe(0);
});
