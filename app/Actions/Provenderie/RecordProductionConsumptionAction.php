<?php

namespace App\Actions\Provenderie;

use App\Models\MillProduction;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RecordProductionConsumptionAction
{
    /**
     * Déstocke les matières premières consommées par une production.
     */
    public function execute(MillProduction $production): float
    {
        $production->loadMissing('formula.items.rawMaterial');
        $quantityProduced = (float) $production->quantity_produced;
        $totalCost = 0;

        foreach ($production->formula->items as $item) {
            $material = $item->rawMaterial;

            if (! $material) {
                Log::warning("[Provenderie] Ingrédient orphelin dans formule {$production->formula->name}, item #{$item->id}");
                continue;
            }

            // Quantité nécessaire = (pourcentage / 100) × quantité totale produite
            $quantityNeeded = ($item->percentage / 100) * $quantityProduced;

            if ($material->stock_qty < $quantityNeeded) {
                throw new RuntimeException(
                    "Stock insuffisant pour {$material->name} : " .
                    "nécessaire " . round($quantityNeeded, 2) . " {$material->unit}, " .
                    "disponible " . round($material->stock_qty, 2) . " {$material->unit}"
                );
            }

            // Décrémenter le stock de la matière première
            $material->decrement('stock_qty', $quantityNeeded);

            // Calcul du coût réel
            $totalCost += $quantityNeeded * (float) $material->unit_cost;

            Log::info(
                "[Provenderie] MP déstockée : {$material->name} " .
                "-{$quantityNeeded} {$material->unit} pour OP #{$production->batch_number}"
            );
        }

        return $totalCost;
    }
}