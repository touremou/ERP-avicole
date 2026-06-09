<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Stock;

class StockFixSeeder extends Seeder {
    public function run() {
        // Mise à jour sécurisée du stock
        Stock::where('item_name', 'LIKE', '%Aliment Démarrage%')
             ->update(['alert_threshold' => 50]);
             
        // On en profite pour initialiser les autres stocks si besoin
        Stock::whereNull('alert_threshold')->update(['alert_threshold' => 10]);
    }
}

//php artisan db:seed --class=StockFixSeeder