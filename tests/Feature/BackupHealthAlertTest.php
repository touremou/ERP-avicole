<?php

use App\Services\BackupHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\AviSmartTestHelper;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class, AviSmartTestHelper::class);

/*
 * UNE SAUVEGARDE QUI ÉCHOUE N'ÉTAIT ANNONCÉE À PERSONNE.
 *
 * Deux runbooks — déploiement et sauvegarde/restauration — désignent
 * `backup:monitor` comme LE contrôle de santé des sauvegardes. Il n'était PLANIFIÉ
 * nulle part. Et même lancé, il n'aurait prévenu personne : toutes les notifications
 * de la bibliothèque sont désactivées dans config/backup.php, volontairement, « pour
 * ne pas dépendre d'une configuration mail en production ».
 *
 * Deux lecteurs documentés, aucun rédacteur — le défaut dominant de tout cet audit,
 * appliqué au seul incident qui ne se rattrape pas. Une panne de disque sans
 * sauvegarde met fin à l'exploitation ; tout le reste se répare.
 *
 * ─── LES CHOIX DE CE LOT ───
 *
 * L'alerte passe par la CHAÎNE de l'application (cloche, push, e-mail, WhatsApp) et
 * non par le canal mail de la bibliothèque : sur cette installation le WhatsApp est
 * en mode journal et le SMTP n'est pas garanti — une alerte à canal unique
 * n'atteindrait personne (#216, #217).
 *
 * AUDIENCE IMPOSÉE : les administrateurs. Cette alerte s'adresse à une FONCTION, pas
 * à qui a coché une case. Cela évite aussi d'inventer un type d'abonnement sans
 * correspondance — l'absence de correspondance vaut « aucun filtre », c'est-à-dire un
 * WhatsApp à TOUT LE MONDE, et le WhatsApp coûte de l'argent (le piège de #216).
 *
 * SILENCIEUSE QUAND TOUT VA BIEN : une alerte quotidienne « sauvegarde OK » finirait
 * ignorée, et cette habitude déteindrait sur les alertes qui comptent.
 */

beforeEach(function () {
    $this->setUpRbac();
    $this->adminUser->update(['whatsapp_phone' => '+224620000000']);
});

/** Notifications in-app reçues par un compte. */
function backupBellCount(int $userId): int
{
    return DB::table('notifications')->where('notifiable_id', $userId)->count();
}

test('aucune sauvegarde → les administrateurs sont alertés', function () {
    Storage::fake('backups');

    $this->artisan('avismart:check-backups')->assertExitCode(1);

    expect(backupBellCount($this->adminUser->id))->toBeGreaterThan(0);
});

test('une sauvegarde trop ancienne → alerte', function () {
    $disk = Storage::fake('backups');
    $disk->put('avismart/vieille.zip', 'contenu');
    touch($disk->path('avismart/vieille.zip'), now()->subDays(5)->timestamp);

    $this->artisan('avismart:check-backups')->assertExitCode(1);

    expect(backupBellCount($this->adminUser->id))->toBeGreaterThan(0);
});

test('une sauvegarde RÉCENTE → aucune alerte, et un code de sortie nul', function () {
    // Le pendant indispensable : une alerte quotidienne « tout va bien » finirait
    // ignorée, et l'habitude déteindrait sur celles qui comptent.
    $disk = Storage::fake('backups');
    $disk->put('avismart/cette-nuit.zip', 'contenu');

    $this->artisan('avismart:check-backups')->assertExitCode(0);

    expect(backupBellCount($this->adminUser->id))->toBe(0);
});

test('l’alerte arrive par la CLOCHE, WhatsApp muet ou non', function () {
    // Sur cette installation le canal WhatsApp est en mode journal. Une alerte qui
    // n'existerait que là n'atteindrait personne — sur le seul incident
    // irréversible, ce serait le silence le plus cher de l'application.
    \App\Models\Setting::set('whatsapp.driver', 'log');
    Storage::fake('backups');

    $this->artisan('avismart:check-backups');

    $payload = (string) DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)->value('data');

    expect($payload)->toContain('AUCUNE SAUVEGARDE')
        ->and($payload)->toContain('critique');
});

test('l’alerte mène à l’écran des sauvegardes', function () {
    Storage::fake('backups');

    $this->artisan('avismart:check-backups');

    $payload = (string) DB::table('notifications')
        ->where('notifiable_id', $this->adminUser->id)->value('data');

    expect($payload)->toContain(route('backups.index', absolute: false));
});

test('sans AUCUN administrateur, la commande le DIT au lieu de rendre un succès muet', function () {
    // Sans destinataire, l'alerte n'existe pas. Rendre 0 laisserait croire que tout
    // va bien — exactement le genre de succès trompeur corrigé en #215.
    Storage::fake('backups');
    DB::table('users')->update(['is_active' => false]);

    $this->artisan('avismart:check-backups')
        ->expectsOutputToContain('AUCUN administrateur')
        ->assertExitCode(1);
});

test('le contrôle est PLANIFIÉ, après la sauvegarde de la nuit', function () {
    // Un contrôle de santé qui ne tourne pas est exactement le défaut d'origine :
    // `backup:monitor` était documenté dans deux runbooks et planifié nulle part.
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->toContain("Schedule::command('avismart:check-backups')");

    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    $backup = $events->first(fn ($e) => str_contains($e->command ?? '', 'backup:run'));
    $check  = $events->first(fn ($e) => str_contains($e->command ?? '', 'avismart:check-backups'));

    expect($backup)->not->toBeNull()->and($check)->not->toBeNull();

    // Le contrôle passe APRÈS la sauvegarde : l'inverse jugerait la nuit précédente.
    expect($check->expression)->toBe('0 3 * * *')
        ->and($backup->expression)->toBe('0 2 * * *');
});

test('le diagnostic et l’alerte lisent la MÊME règle', function () {
    // Deux jugements sur la même question se contrediraient tôt ou tard : l'écran
    // dirait « au vert » pendant que la nuit alerterait, ou l'inverse.
    $diagnostic = file_get_contents(app_path('Console/Commands/InstallationDiagnostic.php'));
    $alerte     = file_get_contents(app_path('Console/Commands/CheckBackupHealth.php'));

    expect($diagnostic)->toContain('BackupHealth')
        ->and($alerte)->toContain('BackupHealth')
        // Et le diagnostic ne relit plus le disque pour son compte.
        ->and($diagnostic)->not->toContain("Storage::disk('backups')");
});

test('le seuil de PREUVE du planificateur est plus court que celui de santé', function () {
    // On ne conclut pas « le cron tourne » sur une sauvegarde de l'avant-veille,
    // alors qu'on tolère 48 h avant de crier à l'arrêt. Deux questions, deux seuils.
    expect(BackupHealth::SCHEDULER_PROOF_HOURS)->toBeLessThan(BackupHealth::MAX_AGE_HOURS);
});
