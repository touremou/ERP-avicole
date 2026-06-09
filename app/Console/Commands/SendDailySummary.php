<?php

namespace App\Console\Commands;

use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Envoie le résumé quotidien WhatsApp.
 *
 * Planification dans app/Console/Kernel.php :
 *   $schedule->command('avismart:daily-summary')->dailyAt('07:00');
 *
 * Ou via Windows Task Scheduler (WAMP) :
 *   php artisan avismart:daily-summary
 */
class SendDailySummary extends Command
{
    protected $signature = 'avismart:daily-summary';
    protected $description = 'Envoie le résumé quotidien WhatsApp aux abonnés';

    public function handle(NotificationHub $hub): int
    {
        $this->info('🌅 Compilation du résumé quotidien AviSmart...');

        $sent = $hub->sendDailySummary();

        $this->info("✅ Résumé envoyé à {$sent} destinataire(s).");

        return self::SUCCESS;
    }
}
