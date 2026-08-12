<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\BackupHealth;
use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * ALERTE SI LA SAUVEGARDE NE TOURNE PLUS.
 *
 * Deux runbooks désignent `backup:monitor` comme le contrôle de santé des
 * sauvegardes. Il n'était PLANIFIÉ nulle part. Et même lancé, il n'aurait prévenu
 * personne : toutes les notifications de la bibliothèque sont désactivées dans
 * config/backup.php — volontairement, « pour ne pas dépendre d'une configuration
 * mail en production ».
 *
 * Une sauvegarde qui échoue à 02:00 n'était donc annoncée à personne, et le contrôle
 * censé s'en apercevoir ne tournait pas. Sur le seul incident irréversible de cette
 * exploitation, c'est le silence qui coûtait le plus cher.
 *
 * ─── CE QUE FAIT CETTE COMMANDE ───
 *
 * Elle lit la MÊME règle que le diagnostic (BackupHealth) et, si la sauvegarde est
 * malade, alerte par la chaîne de l'application — cloche, push, e-mail, WhatsApp —
 * plutôt que par le canal mail de la bibliothèque, qui dépendrait d'une configuration
 * que cette installation n'a pas.
 *
 * AUDIENCE IMPOSÉE : les administrateurs. Cette alerte s'adresse à une FONCTION, pas
 * à qui a coché une case — comme l'avis de réception d'expédition (#216). Elle évite
 * aussi d'inventer un type d'abonnement, dont l'absence de correspondance vaudrait
 * « aucun filtre », c'est-à-dire un WhatsApp à tout le monde (le piège de #216).
 *
 * SILENCIEUSE QUAND TOUT VA BIEN : une alerte quotidienne « sauvegarde OK » finirait
 * ignorée, et l'ignorance déteindrait sur celles qui comptent.
 */
class CheckBackupHealth extends Command
{
    protected $signature = 'avismart:check-backups';

    protected $description = 'Alerte les administrateurs si la sauvegarde quotidienne ne tourne plus';

    public function handle(BackupHealth $health, NotificationHub $hub): int
    {
        $state = $health->assess();

        if ($state['healthy']) {
            $this->info("Sauvegardes saines : {$state['count']} fichier(s), la plus récente il y a {$state['age_hours']} h.");

            return self::SUCCESS;
        }

        $message = match (true) {
            ! $state['reachable'] => "🔴 *SAUVEGARDE INJOIGNABLE*\n\nLe disque de sauvegarde ne répond pas : {$state['error']}\n\nAucune sauvegarde ne peut être écrite.",
            $state['count'] === 0 => "🔴 *AUCUNE SAUVEGARDE*\n\nIl n’existe aucune sauvegarde de l’exploitation. Une panne de disque effacerait tout — c’est le seul incident qui ne se rattrape pas.",
            default               => "🔴 *SAUVEGARDE ARRÊTÉE*\n\nLa dernière sauvegarde date de " . round($state['age_hours'] / 24, 1) . " jour(s). La sauvegarde quotidienne ne tourne plus.",
        };

        $message .= "\n\nVérifier le planificateur (schedule:run) puis lancer php artisan backup:run.";

        $admins = $this->administrators();

        if ($admins->isEmpty()) {
            // On le DIT plutôt que de rendre un succès muet : sans destinataire,
            // l'alerte n'existe pas.
            $this->error('Sauvegarde en défaut, et AUCUN administrateur à prévenir.');

            return self::FAILURE;
        }

        $hub->alertBackupFailure($message, $admins);

        $this->error('Sauvegarde en défaut : ' . $admins->count() . ' administrateur(s) alerté(s).');

        return self::FAILURE;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function administrators()
    {
        $admins = User::where('is_active', true)
            ->whereHas('userRole', fn ($q) => $q->where('name', 'admin'))
            ->get();

        if ($admins->isNotEmpty()) {
            return $admins;
        }

        // Repli par role_id, comme ErrorAlertService : la relation `userRole` est la
        // bonne, mais une base ancienne peut porter des rôles non nommés « admin ».
        $roleId = Role::where('name', 'admin')->value('id');

        return $roleId ? User::where('is_active', true)->where('role_id', $roleId)->get() : collect();
    }
}
