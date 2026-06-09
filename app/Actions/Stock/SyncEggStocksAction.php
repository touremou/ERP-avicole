<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\EggProduction;
use Illuminate\Support\Facades\DB;

class SyncEggStocksAction
{
    public function execute(): void
    {
        DB::transaction(function () {
            $eggMapping = [
                'S'        => 'grade_s', 
                'M'        => 'grade_m', 
                'L'        => 'grade_l', 
                'XL'       => 'grade_xl', 
                'Cassé'    => 'broken_eggs', 
                'Anomalie' => 'small_eggs'
            ];
            
            foreach ($eggMapping as $stockName => $prodField) {
                $totalProduit = EggProduction::sum($prodField);
                $stock = Stock::where('item_name', $stockName)->where('category', 'oeufs')->first();
                if ($stock) {
                    $stock->update(['current_quantity' => max(0, $totalProduit)]);
                }
            }
        });
    }
}