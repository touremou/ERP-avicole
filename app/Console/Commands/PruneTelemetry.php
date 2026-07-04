<?php

namespace App\Console\Commands;

use App\Models\TelemetryLog;
use Illuminate\Console\Command;

/**
 * Rétention de la zone tampon IoT : sans purge, un capteur par bâtiment
 * pendant des mois fait grossir telemetry_logs indéfiniment (l'anti-spam
 * limite le débit, la purge limite l'historique).
 */
class PruneTelemetry extends Command
{
    protected $signature = 'telemetry:prune {--days=90 : Ancienneté maximale conservée}';

    protected $description = 'Purge les relevés IoT plus vieux que la rétention (défaut 90 jours)';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $deleted = TelemetryLog::where('recorded_at', '<', now()->subDays($days))->delete();

        $this->info("Télémétrie : {$deleted} relevé(s) purgé(s) (> {$days} j).");

        return self::SUCCESS;
    }
}
