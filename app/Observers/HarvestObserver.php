<?php

namespace App\Observers;

use App\Models\Harvest;

/**
 * Maintient crop_cycles.total_revenue synchronisé avec les récoltes réelles.
 * Déclenché sur created / updated / deleted afin que la valeur soit toujours
 * égale à Σ(récolte.quantité × récolte.prix_unitaire) du cycle.
 */
class HarvestObserver
{
    public function created(Harvest $harvest): void
    {
        $harvest->cropCycle?->recalculateRevenue();
    }

    public function updated(Harvest $harvest): void
    {
        $harvest->cropCycle?->recalculateRevenue();
    }

    public function deleted(Harvest $harvest): void
    {
        $harvest->cropCycle?->recalculateRevenue();
    }
}
