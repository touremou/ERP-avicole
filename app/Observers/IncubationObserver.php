<?php

namespace App\Observers;

use App\Models\Incubation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncubationObserver
{
    public function creating(Incubation $incubation): void
    {
        // On s'assure que les valeurs par défaut sont correctes
        $incubation->status = 'incubation';
        
        if (!$incubation->start_date) {
            $incubation->start_date = now();
        }

        // Si la date d'éclosion n'est pas fournie, on estime à J+21 (standard poule)
        if (!$incubation->hatch_date_expected) {
            $incubation->hatch_date_expected = Carbon::parse($incubation->start_date)->addDays(21);
        }
    }

    public function deleted(Incubation $incubation): void
    {
        // En cas de suppression (erreur de saisie), on libère la machine
        // UNIQUEMENT si aucune autre incubation active n'est dedans
        if ($incubation->incubator) {
            $hasOtherActive = Incubation::where('incubator_id', $incubation->incubator_id)
                                        ->where('id', '!=', $incubation->id)
                                        ->where('status', '!=', 'clos')
                                        ->exists();

            if (!$hasOtherActive) {
                $incubation->incubator->update(['status' => 'Disponible']);
            }
        }
    }
}