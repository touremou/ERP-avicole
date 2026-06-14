<?php

namespace App\Actions\MillProduction;

use App\Models\Formula;
use App\Models\MillProduction;
use App\Models\Stock;
// NOUVEAUX IMPORTS
use App\Actions\Provenderie\RecordProductionConsumptionAction;
use App\Actions\Provenderie\NormalizeFormulaNameAction;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteMillProduction
{
    // INJECTION DES DEUX NOUVELLES ACTIONS (À LA PLACE DE STOCKSERVICE)
    public function __construct(
        private RecordProductionConsumptionAction $recordConsumption,
        private NormalizeFormulaNameAction $normalizeName
    ) {}

    public function execute(MillProduction $production): MillProduction
    {
        if ($production->status === 'Terminé') {
            throw new \DomainException("L'OP #{$production->batch_number} est déjà clôturée.");
        }

        $production->load(['formula.items.rawMaterial', 'formula.productionType.species', 'machine', 'machines']);
        $quantityProduced = (float) $production->quantity_produced;

        // ─── 1. VÉRIFICATION PRÉALABLE DES STOCKS MP ───
        $insufficientItems = [];
        foreach ($production->formula->items as $item) {
            $material = $item->rawMaterial;
            if (! $material) continue;

            $needed = ($item->percentage / 100) * $quantityProduced;
            if ($material->stock_qty < $needed) {
                $insufficientItems[] = "{$material->name} (besoin: " . round($needed, 1) .
                    " {$material->unit}, dispo: " . round($material->stock_qty, 1) . ")";
            }
        }

        if (! empty($insufficientItems)) {
            throw new \RuntimeException(
                "Stock insuffisant pour : " . implode(', ', $insufficientItems)
            );
        }

        // ─── 2. MAPPING NOM DE STOCK FINI (UTILISATION DE LA NOUVELLE ACTION) ───
        // On passe la formule pour cibler le secteur d'aliment de son espèce
        // (multiespèces : Chair/Ponte mais aussi Engraissement, Laitière...).
        $stockItemName = $this->normalizeName->execute(
            $production->formula->name,
            $production->formula
        );

        // ─── 2.bis PROVISION DU SILO D'ALIMENT FINI ───
        // Multiespèces : le silo cible peut ne pas encore exister (aucun
        // aliment n'est seedé). On le crée à la volée dans le bon secteur
        // pour que l'entrée de stock ci-dessous aboutisse quelle que soit
        // l'espèce, au lieu d'échouer sur « article introuvable ».
        $this->ensureFinishedFeedStock($stockItemName, $production->formula);

        return DB::transaction(function () use ($production, $quantityProduced, $stockItemName) {

            // ─── 3. DÉSTOCKAGE MP (UTILISATION DE LA NOUVELLE ACTION) ───
            $totalCost = $this->recordConsumption->execute($production);
            $realCostPerKg = $quantityProduced > 0
                ? round($totalCost / $quantityProduced, 2)
                : 0;

            // ─── 4. ENTRÉE STOCK ALIMENT FINI ───
            $synced = StockIntegrationService::syncMovement(
                $stockItemName,
                'conso',
                $quantityProduced,
                'in',
                "Production OP #{$production->batch_number}",
                'KG'
            );

            if (! $synced) {
                throw new \RuntimeException(
                    "L'article '{$stockItemName}' est introuvable dans le catalogue stock. " .
                    "Vérifier le mapping."
                );
            }
            // ─── 4.5. VÉRIFICATION SÉCURITÉ MACHINES ───
            foreach ($production->machines as $machine) {
                if ($machine->status === 'En Panne') {
                    throw new \DomainException(
                        "Clôture impossible : la machine '{$machine->name}' est déclarée 'En Panne'. " .
                        "Veuillez enregistrer la maintenance et la remettre en statut 'Opérationnel' avant de valider l'OP."
                    );
                }
            }

            // ─── 5. USURE DES MACHINES ───
            $allMachines = collect([$production->machine])
                ->merge($production->machines ?? collect())
                ->filter()
                ->unique('id');

            foreach ($production->machines as $machine) {
                // On utilise la capacité figée au moment de la création de l'OP !
                $capacityAtTheTime = (float) $machine->pivot->snapshot_capacity_per_hour;
                
                if ($capacityAtTheTime <= 0) continue;

                $hoursWorked = $quantityProduced / $capacityAtTheTime;
                $machine->increment('total_hours_run', $hoursWorked);
            }

            // ─── 6. FINALISATION OP ───
            $production->update([
                'status'          => 'Terminé',
                'finished_at'     => now(),
                'real_cost_per_kg' => $realCostPerKg,
            ]);

            return $production->fresh();
        });
    }

    /**
     * Garantit l'existence du silo d'aliment fini (article de stock « conso »)
     * correspondant, en le créant à 0 dans le secteur de la formule s'il est
     * absent. Idempotent (firstOrCreate sur item_name + category).
     */
    private function ensureFinishedFeedStock(string $itemName, Formula $formula): void
    {
        Stock::firstOrCreate(
            ['item_name' => $itemName, 'category' => Stock::CAT_CONSO],
            [
                'feed_type'        => $itemName,
                'unit'             => 'KG',
                'current_quantity' => 0,
                'alert_threshold'  => 0,
                'metadata'         => [
                    'poultry_type' => $formula->feedSector(),
                    'conso_type'   => 'Aliment',
                ],
            ]
        );
    }
}