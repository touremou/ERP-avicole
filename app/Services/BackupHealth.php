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

        $disks = count((array) config('backup.backup.destination.disks', []));

        if ($files === []) {
            return [
                'reachable' => true, 'count' => 0, 'age_hours' => null,
                'healthy' => false, 'offsite' => $disks > 1, 'disks' => $disks,
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
            'offsite'   => $disks > 1,
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
}
