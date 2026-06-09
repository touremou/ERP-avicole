<?php

namespace App\Console\Commands;

use App\Services\TaskSchedulerService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Génère automatiquement les tâches quotidiennes depuis les templates.
 *
 * Usage :
 *   php artisan tasks:generate              → Aujourd'hui
 *   php artisan tasks:generate --date=2026-06-10  → Date spécifique
 *   php artisan tasks:generate --days=7     → 7 jours d'avance
 *
 * Cron (dans app/Console/Kernel.php ou routes/console.php) :
 *   $schedule->command('tasks:generate')->dailyAt('05:00');
 */
class GenerateDailyTasks extends Command
{
    protected $signature = 'tasks:generate
        {--date= : Date spécifique (Y-m-d)}
        {--days=1 : Nombre de jours à générer}
        {--farm= : ID ferme spécifique (sinon toutes)}';

    protected $description = 'Génère les tâches quotidiennes depuis les templates actifs';

    public function handle(TaskSchedulerService $service): int
    {
        $startDate = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now();

        $days = (int) $this->option('days');
        $farmId = $this->option('farm') ? (int) $this->option('farm') : null;

        // Si pas de ferme spécifiée, générer pour chaque ferme
        if (!$farmId && \Illuminate\Support\Facades\Schema::hasTable('farms')) {
            $farmIds = \Illuminate\Support\Facades\DB::table('farms')->pluck('id')->toArray();
            if (empty($farmIds)) $farmIds = [null];
        } else {
            $farmIds = [$farmId];
        }

        $this->info("Génération des tâches pour " . count($farmIds) . " ferme(s)...");

        $totalCreated = 0;
        $totalOverdue = 0;

        foreach ($farmIds as $fId) {
            for ($i = 0; $i < $days; $i++) {
                $date = $startDate->copy()->addDays($i);
                $result = $service->generateForDate($date, $fId);

                $farmLabel = $fId ? "Farm #{$fId}" : 'Global';
                $this->line("  [{$farmLabel}] {$date->format('d/m/Y')} : {$result['created']} créées, {$result['skipped']} existantes");

                $totalCreated += $result['created'];
                $totalOverdue += $result['overdue'];
            }
        }

        $this->info("Terminé : {$totalCreated} tâches, {$totalOverdue} en retard.");

        return self::SUCCESS;
    }
}
