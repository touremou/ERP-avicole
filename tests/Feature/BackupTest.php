<?php

use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

beforeEach(function () {
    $this->setUpRbac();
    Storage::fake('backups');
});

test('l\'admin voit la page des sauvegardes et la liste', function () {
    // Une fausse archive présente sur le disque doit apparaître.
    Storage::disk('backups')->put('AviSmart/2026-06-27-02-00-00.zip', 'zip-bytes');

    $this->actingAs($this->adminUser)
        ->get(route('backups.index'))
        ->assertOk()
        ->assertSee('Sauvegardes')
        ->assertSee('2026-06-27-02-00-00.zip');
});

test('un non-admin ne peut pas accéder aux sauvegardes', function () {
    // Verrou de route can:admin.S (défense en profondeur) : refus → redirection
    // (la cible exacte dépend du gestionnaire d'exceptions ; l'important est le refus).
    $response = $this->actingAs($this->operatorUser)
        ->get(route('backups.index'));

    expect($response->status())->toBeIn([302, 403]);
    $response->assertDontSee('Sauvegardes');
});

test('le téléchargement refuse un nom hors du disque (anti path-traversal)', function () {
    $this->actingAs($this->adminUser)
        ->get(route('backups.download', ['name' => '..%2F..%2Fsecret.zip']))
        ->assertNotFound();
});

test('le téléchargement d\'une archive existante fonctionne pour l\'admin', function () {
    Storage::disk('backups')->put('AviSmart/dump.zip', 'zip-bytes');

    $this->actingAs($this->adminUser)
        ->get(route('backups.download', ['name' => 'dump.zip']))
        ->assertOk();
});

test('un non-admin ne peut pas télécharger une sauvegarde', function () {
    Storage::disk('backups')->put('AviSmart/dump.zip', 'zip-bytes');

    // L'app convertit globalement abort(403) en redirection (cf. bootstrap/app.php).
    $this->actingAs($this->operatorUser)
        ->get(route('backups.download', ['name' => 'dump.zip']))
        ->assertRedirect();
});

test('une ligne BACKUP_ARCHIVE_PASSWORD vide ne chiffre pas avec un mot de passe vide', function () {
    // Vide dans le .env = chaîne vide : spatie chiffrait avec '' et TOUTE
    // sauvegarde échouait (« ZipArchive::close(): Invalid argument »).
    putenv('BACKUP_ARCHIVE_PASSWORD=');
    $_ENV['BACKUP_ARCHIVE_PASSWORD'] = $_SERVER['BACKUP_ARCHIVE_PASSWORD'] = '';
    expect((include config_path('backup.php'))['backup']['password'])->toBeNull();

    putenv('BACKUP_ARCHIVE_PASSWORD=Secret#Client');
    $_ENV['BACKUP_ARCHIVE_PASSWORD'] = $_SERVER['BACKUP_ARCHIVE_PASSWORD'] = 'Secret#Client';
    expect((include config_path('backup.php'))['backup']['password'])->toBe('Secret#Client');

    putenv('BACKUP_ARCHIVE_PASSWORD');
    unset($_ENV['BACKUP_ARCHIVE_PASSWORD'], $_SERVER['BACKUP_ARCHIVE_PASSWORD']);
});
