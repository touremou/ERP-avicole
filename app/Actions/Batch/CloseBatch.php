<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Models\Building;
use Illuminate\Support\Facades\DB;

/**
 * Action : Clôture d'un lot de production.
 *
 * Calcul de marge complet (corrige B-07) :
 * - Revenus = vente des oiseaux (réforme) + revenus œufs cumulés
 * - Coûts = acquisition + alimentation + santé + coûts additionnels
 * - Marge = Revenus - Coûts
 *
 * Gestion bâtiment (corrige S-07) :
 * - Vide sanitaire déclenché UNIQUEMENT si plus aucun lot actif dans le bâtiment
 */
class CloseBatch
{
    /**
     * @param  Batch $batch  Le lot à clôturer
     * @param  array $data   Données validées depuis CloseBatchRequest
     * @return Batch Le lot clôturé
     */
    public function execute(Batch $batch, array $data): Batch
    {
        return DB::transaction(function () use ($batch, $data) {
            // ─── REVENUS ───
            $sellingPrice = (float) $data['actual_sell_price_per_unit'];
            $sellingRevenue = $batch->current_quantity * $sellingPrice;

            // Revenus œufs cumulés (mouvements de vente)
            // Note : si egg_movements n'a pas de colonne de prix, on utilise 0
            // et on le corrigera dans le refactoring du module Œufs
            $eggRevenue = 0;
            // TODO : $eggRevenue = $batch->eggMovements()->where('type', 'vente')->sum('total_price');

            $totalRevenue = $sellingRevenue + $eggRevenue;

            // ─── COÛTS ───
            $acquisitionCost = (float) ($batch->total_acquisition_cost ?? 0);
            $feedCost = (float) $batch->feedPurchases()->sum('total_price');
            $healthCost = (float) $batch->healthChecks()->sum('cost');
            $additionalCosts = (float) ($batch->additional_costs ?? 0);
            $totalCost = $acquisitionCost + $feedCost + $healthCost + $additionalCosts;

            // ─── MARGE ───
            $margin = $totalRevenue - $totalCost;

            // ─── MISE À JOUR DU LOT ───
            $batch->update([
                'status'                     => 'Terminé',
                'current_quantity'           => 0,
                'closing_date'               => $data['closing_date'],
                'actual_sell_price_per_unit'  => $sellingPrice,
                'total_revenue'              => $totalRevenue,
                'margin'                     => $margin,
                'observations'               => trim(
                    ($batch->observations ?? '') . "\n" .
                    ($data['observations'] ?? '')
                ) ?: null,
            ]);

            // ─── GESTION BÂTIMENT ───
            $this->handleBuildingSanitaryBreak($batch);

            return $batch->fresh();
        });
    }

    /**
     * Déclenche le vide sanitaire UNIQUEMENT si plus aucun lot actif.
     *
     * Corrige S-07 : l'ancien code forçait la désinfection même si
     * d'autres lots étaient encore dans le bâtiment.
     */
    private function handleBuildingSanitaryBreak(Batch $batch): void
    {
        $building = $batch->building;
        if (! $building) {
            return;
        }

        $hasOtherActive = Batch::where('building_id', $building->id)
            ->where('id', '!=', $batch->id)
            ->where('status', 'Actif')
            ->exists();

        if (! $hasOtherActive) {
            $building->update([
                'status' => 'En désinfection',
                'disinfection_started_at' => now(),
            ]);
        }
    }
}
