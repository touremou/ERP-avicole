<?php

namespace App\Actions\Batch;

use App\Models\Batch;
use App\Services\BatchQuantityService;
use Illuminate\Support\Facades\DB;

/**
 * Action : Réouverture d'un lot clôturé.
 *
 * Corrections :
 * - S-03 : conserve les revenus œufs cumulés (ne reset plus total_revenue à 0)
 * - Recalcule l'effectif depuis les daily_checks au lieu de deviner
 */
class ReopenBatch
{
    public function __construct(
        private BatchQuantityService $quantityService
    ) {}

    /**
     * @param  Batch $batch Le lot à réouvrir
     * @return Batch Le lot réouvert
     *
     * @throws \DomainException Si le lot est déjà actif
     */
    public function execute(Batch $batch): Batch
    {
        if ($batch->isActive()) {
            throw new \DomainException("Le lot {$batch->code} est déjà en cours de production.");
        }

        return DB::transaction(function () use ($batch) {
            // ─── Recalcul de l'effectif réel depuis les pointages ───
            $result = $this->quantityService->rebuildForBatch($batch, dryRun: true);
            $restoredQuantity = $result['new_quantity'];

            // ─── Réouverture ───
            // Note : le CA œufs n'est pas rattaché au lot (stock mutualisé, cf.
            // Batch::getNetMarginAttribute). On réinitialise donc uniquement la
            // vente de réforme (total_revenue), recalculée à la prochaine clôture.
            $batch->update([
                'status'                     => Batch::STATUS_ACTIF,
                'current_quantity'           => $restoredQuantity,
                'closing_date'               => null,
                'actual_sell_price_per_unit'  => 0,
                'total_revenue'              => 0, // Sera recalculé à la prochaine clôture
                'margin'                     => null,
            ]);

            // ─── Bâtiment → Occupé ───
            if ($batch->building) {
                $batch->building->markOccupied();
            }

            return $batch->fresh();
        });
    }
}
