<?php

namespace App\Services;

use App\Models\MillProduction;
use App\Models\RawMaterial;
use App\Models\Formula;
use App\Models\MillMachine;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * ProductionService — Gère le cycle complet de production provenderie.
 *
 * BUG CORRIGÉ (B-26) :
 * syncMovement() était appelé SANS $inputUnit → tombait dans guessInputUnit()
 * qui devinait "Sac" pour tout nom contenant "chair/ponte/repro", ce qui
 * multipliait la quantité par 50 (1 Sac = 50 KG).
 *
 * Correction : la provenderie produit en KG → on passe 'KG' explicitement.
 */
class ProductionService
{
    /**
     * Gère le cycle complet de production :
     * Déstockage MP → Usure Machine → Entrée Silo Produit Fini
     */
    public function produce(int $formulaId, float $quantityToProduce, int $machineId)
    {
        return DB::transaction(function () use ($formulaId, $quantityToProduce, $machineId) {

            // 0. CHARGEMENT ET VÉRIFICATIONS
            $formula = Formula::with('items.rawMaterial')->findOrFail($formulaId);
            $machine = MillMachine::findOrFail($machineId);

            if ($machine->status !== 'Opérationnel') {
                throw new Exception("La machine {$machine->name} n'est pas disponible (Statut: {$machine->status})");
            }

            // 1. VÉRIFICATION RIGUREUSE DES STOCKS MP
            foreach ($formula->items as $item) {
                $requiredQty = ($item->percentage / 100) * $quantityToProduce;
                if ($item->rawMaterial->stock_qty < $requiredQty) {
                    throw new Exception(sprintf(
                        "Stock insuffisant pour %s. Besoin : %.2f kg, Disponible : %.2f kg",
                        $item->rawMaterial->name, $requiredQty, $item->rawMaterial->stock_qty
                    ));
                }
            }

            // 2. CALCUL DES COÛTS ET DÉSTOCKAGE MATIÈRES PREMIÈRES
            $totalProductionCost = 0;
            foreach ($formula->items as $item) {
                $qtyToDeduct = ($item->percentage / 100) * $quantityToProduce;
                $material = $item->rawMaterial;

                $totalProductionCost += ($qtyToDeduct * $material->unit_cost);
                $material->decrement('stock_qty', $qtyToDeduct);
            }

            $realCostPerKg = $quantityToProduce > 0 ? ($totalProductionCost / $quantityToProduce) : 0;

            // 3. GESTION DE L'USURE MACHINE
            $usageHours = 0;
            if ($machine->capacity_per_hour > 0) {
                $usageHours = $quantityToProduce / $machine->capacity_per_hour;
                $machine->increment('total_hours_run', $usageHours);

                if ($machine->total_hours_run >= $machine->maintenance_interval_hours) {
                    $machine->update(['status' => 'Maintenance']);
                }
            }

            // 4. CRÉATION DE L'ENREGISTREMENT DE PRODUCTION
            $production = MillProduction::create([
                'batch_number'      => 'LOT-' . now()->format('Ymd-His'),
                'formula_id'        => $formulaId,
                'machine_id'        => $machineId,
                'quantity_produced' => $quantityToProduce,
                'real_cost_per_kg'  => $realCostPerKg,
                'usage_hours'       => $usageHours,
                'operator_id'       => auth()->id() ?? 1,
                'status'            => 'Terminé',
                'finished_at'       => now(),
            ]);

            // 5. SYNCHRO STOCK PRODUIT FINI (ENTRÉE SILO CONSO)
            // B-26 corrigé : on passe 'KG' explicitement car la provenderie produit en KG
            $synced = StockIntegrationService::syncMovement(
                $formula->name,
                'conso',
                (float) $quantityToProduce,
                'in',
                "Production Provenderie Lot #{$production->batch_number} (Coût : " . number_format($realCostPerKg, 2) . " /kg)",
                'KG' // B-26 : unité explicite, pas de guessInputUnit
            );

            if (! $synced) {
                throw new Exception("Erreur de synchronisation : L'article '{$formula->name}' n'existe pas dans le stock de consommation.");
            }

            return $production;
        });
    }
}
