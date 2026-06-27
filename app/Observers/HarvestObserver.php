<?php

namespace App\Observers;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\Stock;
use App\Services\StockIntegrationService;

/**
 * Maintient l'état dérivé d'une récolte de façon INDÉPENDANTE DU CHEMIN
 * (contrôleur, action, import, sync) :
 *
 *  1. crop_cycles.total_revenue = Σ(quantité × prix) du cycle ;
 *  2. réconciliation du STOCK « recoltes » : l'entrée créée à la saisie
 *     (RecordHarvest) est corrigée par delta à l'édition et reversée à la
 *     suppression — sinon le stock dérivait à chaque modification (bug audité) ;
 *  3. retour du cycle en phase EN_COURS si l'on supprime sa dernière récolte
 *     (sinon il restait bloqué en statut « recolte » sans aucune récolte).
 */
class HarvestObserver
{
    public function created(Harvest $harvest): void
    {
        $harvest->cropCycle?->recalculateRevenue();
        // NB : l'entrée stock initiale est faite par RecordHarvest lui-même.
    }

    public function updated(Harvest $harvest): void
    {
        $harvest->cropCycle?->recalculateRevenue();
        $this->reconcileStockOnUpdate($harvest);
    }

    public function deleted(Harvest $harvest): void
    {
        $harvest->cropCycle?->recalculateRevenue();
        $this->reverseStock($harvest);
        $this->rollbackCyclePhase($harvest);
    }

    /**
     * Corrige le stock « recoltes » quand une quantité / unité / article lié
     * change : on annule l'ancien mouvement (valeurs d'origine) puis on
     * applique le nouveau. Ignoré si aucun champ impactant le stock n'a changé
     * (ex. mise à jour du seul drapeau synced_to_stock juste après création).
     */
    private function reconcileStockOnUpdate(Harvest $harvest): void
    {
        if (! $harvest->wasChanged(['quantity', 'unit', 'stock_item_name'])) {
            return;
        }

        $cat   = Stock::CAT_RECOLTES;
        $label = $harvest->cropCycle?->code ?? ('#' . $harvest->crop_cycle_id);

        // Annuler l'ancienne entrée si la récolte était synchronisée.
        if ($harvest->getOriginal('synced_to_stock')) {
            $oldName = trim((string) $harvest->getOriginal('stock_item_name'));
            $oldQty  = (float) $harvest->getOriginal('quantity');
            $oldUnit = $harvest->getOriginal('unit') ?: 'kg';
            if ($oldName !== '' && $oldQty > 0) {
                StockIntegrationService::syncMovement(
                    $oldName, $cat, $oldQty, 'out',
                    "Correction récolte {$label} (ancienne valeur annulée)", $oldUnit
                );
            }
        }

        // Réappliquer la nouvelle entrée si la récolte est synchronisée.
        if ($harvest->synced_to_stock) {
            $name = trim((string) $harvest->stock_item_name);
            $qty  = (float) $harvest->quantity;
            $unit = $harvest->unit ?: 'kg';
            if ($name !== '' && $qty > 0) {
                StockIntegrationService::ensureItem($cat, $name, $unit, (float) ($harvest->unit_price ?? 0));
                StockIntegrationService::syncMovement(
                    $name, $cat, $qty, 'in',
                    "Correction récolte {$label} (nouvelle valeur)", $unit,
                    $harvest->unit_price ?: null
                );
            }
        }
    }

    /** Reverse l'entrée stock d'une récolte synchronisée que l'on supprime. */
    private function reverseStock(Harvest $harvest): void
    {
        if (! $harvest->synced_to_stock) {
            return;
        }

        $name = trim((string) $harvest->stock_item_name);
        $qty  = (float) $harvest->quantity;
        if ($name === '' || $qty <= 0) {
            return;
        }

        $label = $harvest->cropCycle?->code ?? ('#' . $harvest->crop_cycle_id);
        StockIntegrationService::syncMovement(
            $name, Stock::CAT_RECOLTES, $qty, 'out',
            "Annulation récolte supprimée {$label}", $harvest->unit ?: 'kg'
        );
    }

    /** Réouvre le cycle (RECOLTE → EN_COURS) s'il n'a plus aucune récolte. */
    private function rollbackCyclePhase(Harvest $harvest): void
    {
        $cycle = $harvest->cropCycle;
        if ($cycle
            && $cycle->status === CropCycle::STATUS_RECOLTE
            && ! $cycle->harvests()->exists()) {
            $cycle->update(['status' => CropCycle::STATUS_EN_COURS]);
        }
    }
}
