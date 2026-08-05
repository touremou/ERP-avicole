<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Building;
use Carbon\Carbon;

class ReleaseBuildingsFromSanitaryVoid extends Command
{
    // Nom de la commande à taper dans le terminal
    protected $signature = 'farm:release-buildings';
    protected $description = 'Libère les bâtiments dont le vide sanitaire réglé est terminé';

    public function handle()
    {
        // Durée RÉGLÉE (Paramètres › Élevage), et non plus la constante : c'est
        // cette commande qui rend le bâtiment disponible, donc c'est ici que le
        // réglage devait être honoré en premier. Il ne l'était nulle part.
        $days = Building::sanitaryBreakDays();

        $buildings = Building::inSanitaryBreak()
            ->where('disinfection_started_at', '<=', now()->subDays($days))
            ->get();

        foreach ($buildings as $building) {
            $building->update([
                'status' => Building::STATUS_VIDE,
                'disinfection_started_at' => null // Reset pour le prochain cycle
            ]);

            $this->info("Bâtiment {$building->name} libéré et prêt pour un nouveau lot.");
        }

        if ($buildings->isEmpty()) {
            $this->comment("Aucun bâtiment à libérer aujourd'hui (vide sanitaire réglé à {$days} jours).");
        }
    }
}