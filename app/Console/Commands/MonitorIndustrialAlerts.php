<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Building;
use App\Models\Stock;
use App\Models\User;
use App\Notifications\IndustrialAlert;
use Carbon\Carbon;

class MonitorIndustrialAlerts extends Command {
    protected $signature = 'erp:monitor-alerts';
    protected $description = 'Vérifie les vides sanitaires et les stocks critiques.';

    public function handle() {
        $admin = User::where('role', 'admin')->first();

        // 1. Vérification Vide Sanitaire
        $buildings = Building::inSanitaryBreak()->get();
        foreach ($buildings as $b) {
            $daysInSanitation = Carbon::parse($b->updated_at)->diffInDays(now());
            
            if ($daysInSanitation > $b->max_sanitary_days) {
                $admin->notify(new IndustrialAlert([
                    'type' => 'sanitary_delay',
                    'priority' => 'medium',
                    'title' => 'Alerte Vide Sanitaire',
                    'message' => "Le bâtiment {$b->name} est en désinfection depuis {$daysInSanitation} jours. Risque de sous-productivité.",
                    'id_reference' => $b->id
                ]));
            }
        }

        // 2. Vérification Stocks Critiques
        $lowStocks = Stock::whereRaw('current_quantity <= alert_threshold')->get();
        foreach ($lowStocks as $s) {
            $admin->notify(new IndustrialAlert([
                'type' => 'stock_rupture',
                'priority' => 'high',
                'title' => 'Rupture de Stock Imminente',
                'message' => "Stock critique : {$s->item_name}. Reste : {$s->current_quantity} {$s->unit}.",
                'id_reference' => $s->id
            ]));
        }
    }
}