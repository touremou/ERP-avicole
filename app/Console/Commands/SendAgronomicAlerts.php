<?php

namespace App\Console\Commands;

use App\Services\NotificationHub;
use Illuminate\Console\Command;

/**
 * Alertes agronomiques : signale, pour chaque cycle de culture en cours, les
 * risques semis/récolte et alertes météo de sévérité élevée détectés par
 * CropAdvisorService (intelligence agronomique).
 *
 * Planifié dans routes/console.php :
 *   Schedule::command('cultures:agronomic-alerts')->dailyAt('06:45');
 */
class SendAgronomicAlerts extends Command
{
    protected $signature = 'cultures:agronomic-alerts';
    protected $description = 'Notifie les risques agronomiques (semis/récolte, météo) des cycles en cours';

    public function handle(NotificationHub $hub): int
    {
        $this->info('🌾 Analyse agronomique des cycles en cours...');

        $count = $hub->notifyAgronomicRisks();

        $this->info($count > 0
            ? "✅ {$count} cycle(s) signalé(s) aux abonnés."
            : "Aucun risque agronomique à signaler.");

        // Alertes météo prédictives (J+1→J+2) par ferme : fortes pluies,
        // canicule, vent fort annoncés.
        $this->info('🛰️  Analyse des prévisions météo...');
        $forecastCount = $hub->notifyWeatherForecast(2);
        $this->info($forecastCount > 0
            ? "✅ {$forecastCount} ferme(s) avec alerte météo prévisionnelle."
            : "Aucune alerte météo prévisionnelle.");

        return self::SUCCESS;
    }
}
