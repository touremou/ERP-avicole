<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * SANTÉ DES SAUVEGARDES — déclaration UNIQUE.
 *
 * La règle « une sauvegarde est-elle saine ? » vivait dans le diagnostic
 * d'installation seulement, c'est-à-dire nulle part tant que personne ne tape la
 * commande. Elle est ici, et sert deux lecteurs : le diagnostic (à la demande) et
 * `avismart:check-backups` (planifié, qui ALERTE).
 *
 * ─── POURQUOI CETTE CLASSE EXISTE ───
 *
 * Deux runbooks désignent `backup:monitor` comme le contrôle de santé. Il n'était
 * PLANIFIÉ nulle part. Et même lancé, il n'aurait prévenu personne : toutes les
 * notifications de la bibliothèque sont désactivées dans config/backup.php —
 * volontairement, « pour ne pas dépendre d'une configuration mail ».
 *
 * Autrement dit : une sauvegarde qui échoue à 02:00 n'était annoncée à personne, et
 * le contrôle censé s'en apercevoir ne tournait pas. Deux lecteurs documentés, aucun
 * rédacteur — le défaut dominant de tout cet audit, sur le seul incident qui ne se
 * rattrape pas.
 *
 * L'alerte passe donc par la chaîne de l'application (cloche, push, e-mail,
 * WhatsApp) plutôt que par le canal mail de la bibliothèque, qui dépendrait d'une
 * configuration que cette exploitation n'a pas.
 */
class BackupHealth
{
    /** Au-delà, la sauvegarde quotidienne ne tourne manifestement plus. */
    public const MAX_AGE_HOURS = 48;

    /**
     * Une sauvegarde plus jeune que ce seuil est la TRACE que le planificateur a
     * tourné cette nuit. Plus court que MAX_AGE_HOURS : on ne veut pas conclure
     * « le cron tourne » sur une sauvegarde de l'avant-veille.
     */
    public const SCHEDULER_PROOF_HOURS = 36;

    /**
     * @return array{reachable: bool, count: int, age_hours: ?int, healthy: bool,
     *               offsite: bool, disks: int, error: ?string}
     */
    public function assess(): array
    {
        try {
            $disk = Storage::disk('backups');
            $files = $disk->allFiles();
        } catch (\Throwable $e) {
            return [
                'reachable' => false, 'count' => 0, 'age_hours' => null,
                'healthy' => false, 'offsite' => false, 'disks' => 0,
                'error' => $e->getMessage(),
            ];
        }

        $destinations = (array) config('backup.backup.destination.disks', []);
        $disks = count($destinations);
        $offsite = static::hasOffsiteDestination($destinations);

        if ($files === []) {
            return [
                'reachable' => true, 'count' => 0, 'age_hours' => null,
                'healthy' => false, 'offsite' => $offsite, 'disks' => $disks,
                'error' => null,
            ];
        }

        $latest = collect($files)->map(fn ($f) => $disk->lastModified($f))->max();

        $age = (int) now()->diffInHours(\Carbon\Carbon::createFromTimestamp($latest), absolute: true);

        return [
            'reachable' => true,
            'count'     => count($files),
            'age_hours' => $age,
            'healthy'   => $age <= self::MAX_AGE_HOURS,
            'offsite'   => $offsite,
            'disks'     => $disks,
            'error'     => null,
        ];
    }

    /**
     * Le planificateur a-t-il laissé une trace récente ?
     *
     * La sauvegarde de 02:00 est déclenchée par lui, et par lui seul : une
     * sauvegarde de cette nuit PROUVE que le cron de l'hébergeur tourne — ce
     * qu'aucune commande lancée à la main ne peut établir autrement.
     */
    public function schedulerRanRecently(): bool
    {
        $state = $this->assess();

        return $state['age_hours'] !== null && $state['age_hours'] <= self::SCHEDULER_PROOF_HOURS;
    }

    /**
     * UNE DESTINATION QUITTE-ELLE VRAIMENT LA MACHINE ?
     *
     * Ce contrôle se contentait de compter : `$disks > 1`. Or le disque
     * `backups_offsite` a pour racine PAR DÉFAUT storage_path('app/backups-offsite'),
     * c'est-à-dire le MÊME disque physique.
     *
     * Suivre à la lettre le conseil du diagnostic — « Configurer
     * BACKUP_DISKS=backups,backups_offsite » — sans renseigner en plus
     * BACKUP_OFFSITE_PATH donnait donc deux copies côte à côte, l'avertissement
     * qui passe au VERT, et le risque annoncé (« une panne matérielle emporterait
     * les données ET leurs sauvegardes ») entièrement inchangé.
     *
     * C'est pire que le silence : un feu vert obtenu en appliquant le remède
     * recommandé, sur le seul incident que cette exploitation ne peut pas
     * rattraper.
     *
     * La règle est donc DÉRIVÉE du pilote et du chemin, jamais d'une liste de noms :
     *
     *   • pilote non local (s3, ftp, sftp…) → ailleurs par construction ;
     *   • racine HORS de storage_path() → le montage NAS, le disque USB ou le
     *     dossier synchronisé que décrit le runbook (« BACKUP_OFFSITE_PATH →
     *     /mnt/nas/erp-backups ») ;
     *   • racine SOUS storage_path() → meurt avec la machine, quel que soit le nom
     *     du disque ;
     *   • disque non déclaré → ne prouve rien. Le compter rendrait le feu vert
     *     atteignable par une faute de frappe.
     *
     * @param  array<int, string> $destinations
     */
    public static function hasOffsiteDestination(array $destinations): bool
    {
        $storage = rtrim(storage_path(), DIRECTORY_SEPARATOR);

        foreach ($destinations as $name) {
            $disk = config('filesystems.disks.' . $name);

            if (! is_array($disk)) {
                continue;   // disque non déclaré : aucune preuve
            }

            if (($disk['driver'] ?? 'local') !== 'local') {
                return true;
            }

            $root = rtrim((string) ($disk['root'] ?? ''), DIRECTORY_SEPARATOR);

            if ($root !== '' && ! str_starts_with($root, $storage)) {
                return true;
            }
        }

        return false;
    }
}
