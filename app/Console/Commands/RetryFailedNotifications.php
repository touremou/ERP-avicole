<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Réessaie l'envoi des notifications WhatsApp en échec (panne API,
 * coupure réseau...) — fiabilité de livraison adaptée aux connexions
 * instables. S'appuie sur le planificateur cron (routes/console.php),
 * sans nécessiter de worker de file d'attente persistant.
 */
class RetryFailedNotifications extends Command
{
    protected $signature = 'avismart:retry-failed-notifications';
    protected $description = 'Réessaie l\'envoi des notifications WhatsApp en échec';

    public function handle(WhatsAppService $whatsapp): int
    {
        $logs = NotificationLog::retryable()->get();

        $succeeded = 0;

        foreach ($logs as $log) {
            if ($whatsapp->retry($log)) {
                $succeeded++;
            }
        }

        $this->info("🔁 {$logs->count()} notification(s) en échec retentée(s), {$succeeded} réussie(s).");

        return self::SUCCESS;
    }
}
