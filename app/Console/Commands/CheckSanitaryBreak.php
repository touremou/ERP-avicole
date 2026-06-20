<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Building;
use App\Notifications\ProductionAlert;
use App\Models\User;

class CheckSanitaryBreak extends Command
{
    protected $signature = 'erp:check-sanitary';
    protected $description = 'Vérifie les bâtiments dont le vide sanitaire dépasse la durée optimale.';

    public function handle()
    {
        // On récupère les bâtiments en désinfection depuis plus de 14 jours
        $buildings = Building::inSanitaryBreak()
            ->where('updated_at', '<', now()->subDays(Building::SANITARY_BREAK_DAYS))
            ->get();

        foreach ($buildings as $building) {
            $manager = User::where('role', 'admin')->first();
            $manager->notify(new ProductionAlert([
                'priority' => 'medium',
                'title' => 'Alerte Vide Sanitaire',
                'message' => "Le bâtiment {$building->name} est en désinfection depuis plus de 14 jours. Prêt pour un nouveau lot ?",
                'batch_uuid' => null
            ]));
        }
    }
}