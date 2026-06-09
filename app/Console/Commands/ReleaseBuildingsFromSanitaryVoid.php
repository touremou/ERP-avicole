<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Building;
use Carbon\Carbon;

class ReleaseBuildingsFromSanitaryVoid extends Command
{
    // Nom de la commande à taper dans le terminal
    protected $signature = 'farm:release-buildings';
    protected $description = 'Libère les bâtiments dont le vide sanitaire de 14 jours est terminé';

    public function handle()
    {
        // On cherche les bâtiments "En désinfection" depuis 14 jours ou plus
        $buildings = Building::where('status', 'En désinfection')
            ->where('disinfection_started_at', '<=', now()->subDays(14))
            ->get();

        foreach ($buildings as $building) {
            $building->update([
                'status' => 'Vide',
                'disinfection_started_at' => null // Reset pour le prochain cycle
            ]);

            $this->info("Bâtiment {$building->name} libéré et prêt pour un nouveau lot.");
        }

        if ($buildings->isEmpty()) {
            $this->comment("Aucun bâtiment à libérer aujourd'hui.");
        }
    }
}