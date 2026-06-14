<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class CreateStockAction
{
    public function execute(array $data, int $userId): Stock
    {
        return DB::transaction(function () use ($data, $userId) {
            $unit = $data['unit'];
            $quantity = $data['current_quantity'] ?? 0;
            $alertThreshold = $data['alert_threshold'];

            // Règle métier : Conversion Sac -> KG
            if ($unit === 'Sac' && $data['category'] === Stock::CAT_CONSO) {
                $quantity *= 50;
                $alertThreshold *= 50;
                $unit = 'KG'; 
            }

            $stock = Stock::create([
                'item_name'        => trim($data['item_name']),
                'category'         => $data['category'],
                'unit'             => $unit,
                'alert_threshold'  => $alertThreshold,
                'current_quantity' => $quantity,
                'unit_price'       => $data['unit_price'] ?? 0,
                'metadata'         => $data['metadata'] ?? [],
            ]);

            // Enregistrement du mouvement initial si quantité > 0
            if ($stock->current_quantity > 0) {
                StockMovement::create([
                    'stock_id' => $stock->id,
                    'user_id'  => $userId,
                    'type'     => 'in',
                    'quantity' => $stock->current_quantity,
                    'notes'    => "Initialisation (Valeur d'entrée : {$data['current_quantity']} {$data['unit']})",
                ]);
            }

            return $stock;
        });
    }
}