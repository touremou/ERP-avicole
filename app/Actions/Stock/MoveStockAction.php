<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class MoveStockAction
{
    public function execute(int $stockId, string $type, float $quantityInput, ?string $notes, int $userId): void
    {
        DB::transaction(function () use ($stockId, $type, $quantityInput, $notes, $userId) {
            $stock = Stock::lockForUpdate()->find($stockId);
            $oldQuantity = (float) $stock->current_quantity;
            $movQty = $quantityInput;
            $finalNotes = $notes ?? "Mouvement de stock manuel";

            if ($type === 'in') {
                $stock->increment('current_quantity', $quantityInput);
            } elseif ($type === 'out') {
                $stock->decrement('current_quantity', $quantityInput);
            } else {
                $stock->update(['current_quantity' => $quantityInput]);
                $movQty = abs($quantityInput - $oldQuantity); 
                $finalNotes = ($notes ?? "Ajustement") . " (Précédent: {$oldQuantity} -> Nouveau: {$quantityInput})";
            }

            if ($type !== 'adjustment' || $movQty > 0) {
                StockMovement::create([
                    'stock_id' => $stock->id,
                    'user_id'  => $userId,
                    'type'     => $type,
                    'quantity' => $movQty,
                    'notes'    => $finalNotes,
                ]);
            }
        });
    }
}