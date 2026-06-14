<?php

namespace App\Console\Commands;

use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Envoie le digest d'activité par employé (fin de journée).
 *
 * Planifié dans routes/console.php à l'heure whatsapp.activity_digest_hour.
 * Donne au propriétaire hors site la visibilité QUI-a-fait-QUOI :
 * ventes, encaissements, mouvements de stock, annulations.
 */
class SendActivityDigest extends Command
{
    protected $signature = 'avismart:activity-digest';
    protected $description = 'Envoie le digest d\'activité par employé (redevabilité)';

    public function handle(NotificationHub $hub): int
    {
        $this->info('📋 Compilation de l\'activité du jour par employé...');

        $sent = $hub->sendActivityDigest();

        $this->info($sent > 0
            ? "✅ Digest d'activité envoyé à {$sent} destinataire(s)."
            : "ℹ️ Aucune activité aujourd'hui — digest non envoyé.");

        return self::SUCCESS;
    }
}
