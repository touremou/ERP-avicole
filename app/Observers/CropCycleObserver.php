<?php

namespace App\Observers;

use App\Models\CropCycle;
use Illuminate\Support\Facades\Log;

class CropCycleObserver
{
    public function updated(CropCycle $cycle): void
    {
        if ($cycle->wasChanged('status')) {
            Log::channel('daily')->info('CropCycle status changed', [
                'id'     => $cycle->id,
                'from'   => $cycle->getOriginal('status'),
                'to'     => $cycle->status,
                'crop'   => $cycle->crop_name,
                'plot'   => $cycle->plot_id,
                'farm'   => $cycle->farm_id,
            ]);
        }
    }

    public function deleted(CropCycle $cycle): void
    {
        Log::channel('daily')->info('CropCycle deleted', [
            'id'    => $cycle->id,
            'crop'  => $cycle->crop_name,
            'farm'  => $cycle->farm_id,
        ]);
    }
}
