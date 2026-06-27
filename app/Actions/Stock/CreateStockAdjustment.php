<?php

namespace App\Actions\Stock;

use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\NotificationHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre un ajustement de stock FORMEL (démarque / inventaire).
 *
 * Cale la quantité physique sur le comptage réel, écrit un StockMovement
 * (type « adjustment », pour la traçabilité des flux) ET un StockAdjustment
 * (motif + valeur de la démarque). Réutilise l'alerte anti-fraude existante.
 */
class CreateStockAdjustment
{
    public function execute(int $stockId, float $countedQuantity, string $reason, ?string $notes, int $userId, ?string $date = null): StockAdjustment
    {
        return DB::transaction(function () use ($stockId, $countedQuantity, $reason, $notes, $userId, $date) {
            $stock = Stock::lockForUpdate()->findOrFail($stockId);

            $before = (float) $stock->current_quantity;
            $after  = round($countedQuantity, 3);
            $delta  = round($after - $before, 3);

            if ($delta === 0.0) {
                throw ValidationException::withMessages([
                    'counted_quantity' => "Aucun écart : la quantité comptée est identique au stock (aucun ajustement).",
                ]);
            }

            $wasLow   = $stock->is_low;
            $unitCost = (float) ($stock->last_unit_price ?? 0);
            $value    = round(abs($delta) * $unitCost, 2);

            $stock->update(['current_quantity' => $after]);

            // Flux physique (cohérent avec MoveStockAction : quantité = |delta|).
            StockMovement::create([
                'stock_id' => $stock->id,
                'user_id'  => $userId,
                'type'     => 'adjustment',
                'quantity' => abs($delta),
                'notes'    => trim(StockAdjustment::REASONS[$reason] ?? $reason) . " — {$before} → {$after}" . ($notes ? " ({$notes})" : ''),
            ]);

            $adjustment = StockAdjustment::create([
                'stock_id'        => $stock->id,
                'user_id'         => $userId,
                'reference'       => \App\Services\DocumentNumberingService::generate('stock_adjustment'),
                'type'            => $delta < 0 ? 'perte' : 'gain',
                'reason'          => $reason,
                'quantity_before' => $before,
                'quantity_after'  => $after,
                'delta'           => $delta,
                'unit_cost'       => $unitCost,
                'value_impact'    => $value,
                'adjustment_date' => $date ?? now()->toDateString(),
                'notes'           => $notes,
            ]);

            // Alertes (anti-fraude + seuil), comme un mouvement manuel.
            $stock->refresh();
            $hub = app(NotificationHub::class);
            $hub->alertStockAdjustment($stock, $before, (float) $stock->current_quantity, $notes);
            if (! $wasLow && $stock->is_low && $stock->alert_threshold > 0) {
                $hub->alertStockCritical($stock);
            }

            return $adjustment;
        });
    }
}
