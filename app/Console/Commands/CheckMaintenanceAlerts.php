<?php

namespace App\Console\Commands;

use App\Models\EnergySource;
use App\Models\Farm;
use App\Models\TaskAssignment;
use Illuminate\Console\Command;

class CheckMaintenanceAlerts extends Command
{
    protected $signature   = 'maintenance:check {--farm= : ID de ferme spécifique}';
    protected $description = 'Génère des tâches maintenance_preventive pour les actifs dont l\'entretien est dû dans ≤ 48h.';

    public function handle(): int
    {
        $farmQuery = Farm::where('is_active', true);

        if ($farmId = $this->option('farm')) {
            $farmQuery->where('id', $farmId);
        }

        $created = 0;
        $today   = now()->toDateString();

        foreach ($farmQuery->get() as $farm) {
            session(['current_farm_id' => $farm->id]);

            EnergySource::active()
                ->where('type', 'groupe')
                ->get()
                ->each(function (EnergySource $source) use ($farm, $today, &$created) {
                    if (! $this->maintenanceDue($source)) {
                        return;
                    }

                    // Idempotent : une seule tâche par actif par jour
                    $exists = TaskAssignment::withoutGlobalScopes()
                        ->where('farm_id', $farm->id)
                        ->where('category', 'maintenance_preventive')
                        ->whereDate('scheduled_date', $today)
                        ->where('title', 'like', "%{$source->name}%")
                        ->exists();

                    if ($exists) {
                        return;
                    }

                    $hoursLeft = round($source->hours_before_maintenance);
                    $due = $source->next_maintenance_at
                        ? $source->next_maintenance_at->format('d/m/Y')
                        : 'maintenant';

                    TaskAssignment::create([
                        'farm_id'          => $farm->id,
                        'category'         => 'maintenance_preventive',
                        'title'            => "Maintenance requise : {$source->name}",
                        'description'      => "Révision préventive ({$hoursLeft}h restantes, échéance {$due}). Vérifier huile, filtres, courroies.",
                        'scheduled_date'   => $today,
                        'priority'         => $source->needs_maintenance ? 'haute' : 'normale',
                        'status'           => 'a_faire',
                        'is_auto_generated' => true,
                    ]);

                    $created++;
                });
        }

        $this->info("maintenance:check — {$created} tâche(s) de maintenance préventive générée(s).");

        return self::SUCCESS;
    }

    private function maintenanceDue(EnergySource $source): bool
    {
        // Cas 1 : déjà en alerte (compteur heures)
        if ($source->needs_maintenance) {
            return true;
        }

        // Cas 2 : next_maintenance_at dans ≤ 48h
        if ($source->next_maintenance_at && $source->next_maintenance_at->lte(now()->addHours(48))) {
            return true;
        }

        return false;
    }
}
