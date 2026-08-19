<?php

use App\Services\BackupHealth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

/*
 * LE CONTRÔLE « HORS SITE » SE CONTENTAIT DE COMPTER LES DESTINATIONS.
 *
 * Le diagnostic d'installation avertit, dans ses propres mots :
 *
 *     « Les sauvegardes ne partent QUE sur le disque du serveur : une panne
 *       matérielle emporterait les données ET leurs sauvegardes.
 *       → Configurer BACKUP_DISKS=backups,backups_offsite »
 *
 * Et il décidait de passer au vert sur `$disks > 1`.
 *
 * Or le disque `backups_offsite` a pour racine PAR DÉFAUT
 * `storage_path('app/backups-offsite')` — c'est-à-dire le MÊME disque physique.
 * Suivre le conseil à la lettre, sans renseigner en plus `BACKUP_OFFSITE_PATH`,
 * donnait donc :
 *
 *   • deux copies sur le même disque ;
 *   • l'avertissement qui passe au VERT (« Copie sur 2 destination(s) ») ;
 *   • et le risque annoncé — la panne matérielle — inchangé.
 *
 * C'est pire que le silence : un feu vert obtenu en appliquant le remède que
 * l'outil recommande, sur le seul incident que cette exploitation ne peut pas
 * rattraper.
 *
 * ─── CE QUE « HORS SITE » VEUT DIRE, MAINTENANT ───
 *
 * Une destination quitte la machine si :
 *
 *   • son pilote n'est pas local (s3, ftp, sftp…) — elle est ailleurs par
 *     construction ;
 *   • ou sa racine est HORS de storage_path() : c'est le montage NAS, le disque
 *     USB ou le dossier synchronisé que le runbook décrit
 *     (« BACKUP_OFFSITE_PATH → /mnt/nas/erp-backups »).
 *
 * Une racine sous storage_path() meurt avec la machine, quel que soit le nom du
 * disque. La règle est dérivée du pilote et du chemin, pas d'une liste de noms à
 * tenir à jour.
 */

/** Reconfigure les destinations de sauvegarde, comme le ferait le .env. */
function destinations(array $disks): void
{
    config(['backup.backup.destination.disks' => $disks]);
}

/** Déclare un disque local pointant où l'on veut. */
function disqueLocal(string $name, string $root): void
{
    config(["filesystems.disks.{$name}" => [
        'driver' => 'local', 'root' => $root, 'throw' => false, 'report' => false,
    ]]);
}

beforeEach(function () {
    Storage::fake('backups');
});

test('deux destinations sur le MÊME disque ne sont pas « hors site »', function () {
    /*
     * LE défaut : c'est exactement ce que produit le conseil du diagnostic
     * appliqué sans BACKUP_OFFSITE_PATH.
     */
    disqueLocal('backups_offsite', storage_path('app/backups-offsite'));
    destinations(['backups', 'backups_offsite']);

    expect(app(BackupHealth::class)->assess()['offsite'])->toBeFalse();
});

test('une racine HORS de storage/ compte comme hors site', function () {
    /*
     * Le montage NAS, le disque USB, le dossier synchronisé — ce que le runbook
     * décrit : « BACKUP_OFFSITE_PATH → /mnt/nas/erp-backups ».
     */
    disqueLocal('backups_offsite', '/mnt/nas/erp-backups');
    destinations(['backups', 'backups_offsite']);

    expect(app(BackupHealth::class)->assess()['offsite'])->toBeTrue();
});

test('un pilote DISTANT compte comme hors site', function () {
    /*
     * S3, Backblaze, FTP : ailleurs par construction, sans racine locale à
     * examiner.
     */
    config(['filesystems.disks.archives_cloud' => ['driver' => 's3', 'bucket' => 'erp-backups']]);
    destinations(['backups', 'archives_cloud']);

    expect(app(BackupHealth::class)->assess()['offsite'])->toBeTrue();
});

test('une seule destination locale reste NON hors site', function () {
    // Le cas de départ de l'exploitation : rien ne change pour lui.
    destinations(['backups']);

    expect(app(BackupHealth::class)->assess()['offsite'])->toBeFalse();
});

test('un disque INCONNU ne fait pas passer le contrôle au vert', function () {
    /*
     * La borne prudente : un nom de disque qui n'est déclaré nulle part ne
     * prouve rien. Le compter comme hors site rendrait le feu vert atteignable
     * par une faute de frappe.
     */
    destinations(['backups', 'disque_qui_nexiste_pas']);

    expect(app(BackupHealth::class)->assess()['offsite'])->toBeFalse();
});

test('le diagnostic dit quoi faire, pas seulement qu’il faut faire', function () {
    /*
     * Le conseil d'origine s'arrêtait à BACKUP_DISKS — la moitié qui ne protège
     * de rien. Il doit nommer BACKUP_OFFSITE_PATH, qui est la moitié utile.
     */
    $code = file_get_contents(base_path('app/Console/Commands/InstallationDiagnostic.php'));

    expect(str_contains($code, 'BACKUP_OFFSITE_PATH'))->toBeTrue();
});
