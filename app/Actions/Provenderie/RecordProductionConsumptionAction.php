<?php

namespace App\Actions\Provenderie;

use App\Models\MillProduction;
use App\Models\RawMaterial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RecordProductionConsumptionAction
{
    /**
     * Déstocke les matières premières consommées par une production.
     *
     * Chaque matière première est verrouillée puis relue (lockForUpdate) avant
     * la vérification de disponibilité et le décrément : sans cela, deux
     * productions simultanées consommant la même MP pouvaient toutes deux
     * passer le test de stock et provoquer un stock négatif.
     */
    public function execute(MillProduction $production): float
    {
        $production->loadMissing('formula.items.rawMaterial');
        $quantityProduced = (float) $production->quantity_produced;

        return DB::transaction(function () use ($production, $quantityProduced) {
            $totalCost = 0;

            foreach ($production->formula->items as $item) {
                if (! $item->rawMaterial) {
                    Log::warning("[Provenderie] Ingrédient orphelin dans formule {$production->formula->name}, item #{$item->id}");
                    continue;
                }

                // Relecture verrouillée de la ligne stock (anti-concurrence).
                $material = RawMaterial::lockForUpdate()->find($item->rawMaterial->id);

                if (! $material) {
                    Log::warning("[Provenderie] Matière première #{$item->rawMaterial->id} introuvable au verrouillage, item #{$item->id}");
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
        });
    }
}