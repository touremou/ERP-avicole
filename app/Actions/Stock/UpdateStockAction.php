<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class UpdateStockAction
{
    public function execute(Stock $stock, array $data, int $userId): void
    {
        DB::transaction(function () use ($stock, $data, $userId) {
            $oldQuantity = (float) $stock->current_quantity;
            $newQuantity = (float) $data['current_quantity'];
            $unit = $data['unit'];
            $alertThreshold = (float) $data['alert_threshold'];

            if ($unit === 'Sac' && $stock->category === 'conso') {
                $newQuantity *= 50;
                $alertThreshold *= 50;
                $unit = 'KG';
            }

            $stock->update([
                'item_name'        => trim($data['item_name']),
                'unit'             => $unit,
                'alert_threshold'  => $alertThreshold,
                'current_quantity' => $newQuantity,
                'unit_price'       => $data['unit_price'] ?? 0,
                'metadata'         => array_merge($stock->metadata ?? [], $data['metadata'] ?? []),
            ]);

            // Tracabilité de l'ajustement
            if (round($oldQuantity, 3) != round($newQuantity, 3)) {
                $delta = abs($newQuantity - $oldQuantity);
                StockMovement::create([
                    'stock_id' => $stock->id,
                    'user_id'  => $userId,
                    'type'     => 'adjustment',
                    'quantity' => $delta,
                    'notes'    => "Ajustement fiche (Précédent: {$oldQuantity} -> Nouveau: {$newQuantity} {$unit})",
                ]);
            }
        });
    }
}