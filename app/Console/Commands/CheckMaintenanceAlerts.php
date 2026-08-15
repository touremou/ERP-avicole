<?php

namespace App\Console\Commands;

use App\Contracts\MaintainableAsset;
use App\Models\EnergySource;
use App\Models\Farm;
use App\Models\MillMachine;
use App\Models\TaskAssignment;
use Illuminate\Console\Command;

class CheckMaintenanceAlerts extends Command
{
    protected $signature   = 'maintenance:check {--farm= : ID de ferme spécifique}';
    protected $description = 'Génère des tâches maintenance_preventive pour les actifs dont l\'entretien est dû.';

    /**
     * FAMILLES D'ACTIFS SUIVIES, et la façon d'en lister les exemplaires actifs.
     *
     * Cette commande ne connaissait que les GROUPES ÉLECTROGÈNES. Les machines
     * de provenderie portaient pourtant le même indicateur `needs_maintenance`,
     * alimenté à chaque clôture d'ordre de fabrication — sans que rien ne s'en
     * saisisse : le seul autre lecteur était un badge de couleur sur un écran du
     * bureau. Un indicateur avec des lecteurs et aucun acteur.
     *
     * L'enjeu est mécanique : `total_hours_run` franchit le seuil, et la machine
     * continue de tourner jusqu'à la casse. L'atelier de provenderie est ce qui
     * tourne le plus dans cette exploitation.
     *
     * @return array<class-string, \Closure(): \Illuminate\Support\Collection>
     */
    private function assetFamilies(): array
    {
        return [
            EnergySource::class => fn () => EnergySource::active()->where('type', 'groupe')->get(),
            MillMachine::class  => fn () => MillMachine::whereNotIn('status', ['Désactivé'])->get(),
        ];
    }

    public function handle(): int
    {
        $farmQuery = Farm::active();

        if ($farmId = $this->option('farm')) {
            $farmQuery->where('id', $farmId);
        }

        $created = 0;
        $today   = now()->toDateString();

        foreach ($farmQuery->get() as $farm) {
            session(['current_farm_id' => $farm->id]);

            foreach ($this->assetFamilies() as $lister) {
                foreach ($lister() as $asset) {
                    /** @var MaintainableAsset&\Illuminate\Database\Eloquent\Model $asset */
                    if (! $asset->isMaintenanceDue()) {
                        continue;
                    }

                    if ($this->alreadyRaisedToday($farm->id, $asset->maintenanceLabel(), $today)) {
                        continue;
                    }

                    TaskAssignment::create([
                        'farm_id'           => $farm->id,
                        'category'          => 'maintenance_preventive',
                        'title'             => "Maintenance requise : {$asset->maintenanceLabel()}",
                        'description'       => $asset->maintenanceDetail(),
                        'scheduled_date'    => $today,
                        'priority'          => $asset->isMaintenanceOverdue() ? 'haute' : 'normale',
                        'status'            => 'a_faire',
                        'is_auto_generated' => true,
                    ]);

                    $created++;
                }
            }
        }

        $this->info("maintenance:check — {$created} tâche(s) de maintenance préventive générée(s).");

        return self::SUCCESS;
    }

    /** Idempotence : une seule tâche par actif et par jour. */
    private function alreadyRaisedToday(int $farmId, string $label, string $today): bool
    {
        return TaskAssignment::withoutGlobalScopes()
            ->where('farm_id', $farmId)
            ->where('category', 'maintenance_preventive')
            ->whereDate('scheduled_date', $today)
            ->where('title', 'like', "%{$label}%")
            ->exists();
    }
}
