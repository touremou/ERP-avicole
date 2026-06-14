<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;

class MoveStockAction
{
    public function execute(int $stockId, string $type, float $quantityInput, ?string $notes, int $userId, ?string $uuid = null): void
    {
        DB::transaction(function () use ($stockId, $type, $quantityInput, $notes, $userId, $uuid) {
            $stock = Stock::lockForUpdate()->find($stockId);
            $oldQuantity = (float) $stock->current_quantity;
            $wasLow = $stock->is_low;
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
                    'uuid'     => $uuid,
                    'stock_id' => $stock->id,
                    'user_id'  => $userId,
                    'type'     => $type,
                    'quantity' => $movQty,
                    'notes'    => $finalNotes,
                ]);
            }

            // ─── ALERTES ───
            $stock->refresh();
            $hub = app(NotificationHub::class);

            // Ajustement manuel d'inventaire : vecteur de dissimulation de vol.
            if ($type === 'adjustment' && $movQty > 0) {
                $hub->alertStockAdjustment($stock, $oldQuantity, (float) $stock->current_quantity, $notes);
            }

            // Franchissement du seuil d'alerte (toute baisse manuelle).
            if (! $wasLow && $stock->is_low && $stock->alert_threshold > 0) {
                $hub->alertStockCritical($stock);
            }
        });
    }
}