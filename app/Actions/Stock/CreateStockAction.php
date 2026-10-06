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

            // Règle métier : Conversion Sac -> KG, au poids de sac RÉGLÉ
            // (UnitConverter::bagWeight) — et non 50 kg en dur : avec des sacs
            // de 25 kg, « 10 sacs » entraient 500 kg, que la fiche de stock
            // affichait ensuite comme 20 sacs.
            if ($unit === 'Sac' && $data['category'] === Stock::CAT_CONSO) {
                $quantity = \App\Services\UnitConverter::sacksToKg((float) $quantity);
                $alertThreshold = \App\Services\UnitConverter::sacksToKg((float) $alertThreshold);
                $unit = 'KG';
            }

            // Le prix saisi initialise AUSSI le coût moyen pondéré (last_unit_price) :
            // c'est lui qui porte la valorisation de l'inventaire (tableau de bord,
            // total_value). Sans cela un article créé avec un prix serait valorisé
            // à 0 jusqu'au premier achat/production.
            $unitPrice = (float) ($data['unit_price'] ?? 0);

            $itemName = trim($data['item_name']);
            $stock = Stock::create([
                'item_name'        => $itemName,
                'category'         => $data['category'],
                'feed_type'        => ($data['category'] === Stock::CAT_CONSO) ? $itemName : null,
                'unit'             => $unit,
                'alert_threshold'  => $alertThreshold,
                'current_quantity' => $quantity,
                'unit_price'       => $unitPrice,
                'last_unit_price'  => $unitPrice,
                'expiry_date'      => $data['expiry_date'] ?? null,
                'lot_number'       => $data['lot_number'] ?? null,
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