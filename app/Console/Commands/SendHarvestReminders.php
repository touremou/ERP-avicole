<?php

namespace App\Console\Commands;

use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Rappels du calendrier cultural : signale les cycles de culture dont la
 * récolte prévue approche (ou est en retard).
 *
 * Planifié dans routes/console.php :
 *   Schedule::command('cultures:harvest-reminders')->dailyAt('06:30');
 *
 * La fenêtre d'anticipation (jours) est pilotée par le paramètre
 * cultures.harvest_reminder_days (défaut 7).
 */
class SendHarvestReminders extends Command
{
    protected $signature = 'cultures:harvest-reminders {--days= : Fenêtre d\'anticipation en jours}';
    protected $description = 'Notifie les cycles de culture arrivant à maturité (calendrier cultural)';

    public function handle(NotificationHub $hub): int
    {
        $days = (int) ($this->option('days') ?? setting('cultures.harvest_reminder_days', 7));

        $this->info("🌾 Recherche des récoltes prévues dans {$days} jours...");

        $count = $hub->notifyHarvestsDue($days);

        $this->info($count > 0
            ? "✅ {$count} cycle(s) signalé(s) aux abonnés."
            : "Aucune récolte à signaler.");

        return self::SUCCESS;
    }
}
