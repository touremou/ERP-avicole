<?php

namespace App\Observers;

use App\Models\CropCycle;
use App\Models\Plot;
use Illuminate\Support\Facades\Log;

/**
 * Source unique de vérité de l'état dérivé d'un cycle de culture :
 *
 *  - statut de la PARCELLE (en_culture / disponible) recalculé depuis
 *    l'occupation réelle, quel que soit le chemin (contrôleur, action, import,
 *    sync) — évite la parcelle « coincée en culture » après un archivage hors
 *    contrôleur ;
 *  - cascade de suppression des enfants (récoltes, intrants, validations
 *    d'étapes) au soft-delete du cycle : les FK SQL cascadeOnDelete ne se
 *    déclenchant pas sur un soft-delete, les enfants resteraient orphelins.
 *    Passer par delete() déclenche aussi leurs observers (réconciliation stock).
 */
class CropCycleObserver
{
    public function created(CropCycle $cycle): void
    {
        $this->reconcilePlotStatus($cycle->plot);
    }

    public function updated(CropCycle $cycle): void
    {
        if ($cycle->wasChanged('status')) {
            Log::channel('daily')->info('CropCycle status changed', [
                'id'   => $cycle->id,
                'from' => $cycle->getOriginal('status'),
                'to'   => $cycle->status,
                'crop' => $cycle->crop_name,
                'plot' => $cycle->plot_id,
                'farm' => $cycle->farm_id,
            ]);

            $this->reconcilePlotStatus($cycle->plot);
        }
    }

    public function deleting(CropCycle $cycle): void
    {
        // Cascade applicative (les observers enfants se déclenchent : reversal
        // stock des récoltes/intrants synchronisés).
        foreach ($cycle->harvests()->get() as $harvest) {
            $harvest->delete();
        }
        foreach ($cycle->inputs()->get() as $input) {
            $input->delete();
        }
        $cycle->protocolCompletions()->delete();
    }

    public function deleted(CropCycle $cycle): void
    {
        Log::channel('daily')->info('CropCycle deleted', [
            'id'   => $cycle->id,
            'crop' => $cycle->crop_name,
            'farm' => $cycle->farm_id,
        ]);

        $this->reconcilePlotStatus($cycle->plot);
    }

    /**
     * Aligne le statut de la parcelle sur son occupation réelle.
     * On ne bascule qu'entre « disponible » et « en_culture » : un repos
     * (jachère) ou une mise hors exploitation (inactive) reste délibéré et
     * n'est jamais écrasé automatiquement.
     */
    private function reconcilePlotStatus(?Plot $plot): void
    {
        if (! $plot || in_array($plot->status, [Plot::STATUS_JACHERE, Plot::STATUS_INACTIVE], true)) {
            return;
        }

        $target = $plot->isOccupied() ? Plot::STATUS_EN_CULTURE : Plot::STATUS_DISPONIBLE;

        if ($plot->status !== $target) {
            $plot->update(['status' => $target]);
        }
    }
}
