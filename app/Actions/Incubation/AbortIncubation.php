<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class AbortIncubation
{
    public function execute(Incubation $incubation): void
    {
        DB::transaction(function () use ($incubation) {
            $incubateur = $incubation->incubator;

            $incubation->delete();

            /*
             * On libère APRÈS la suppression, et seulement si la machine est vide.
             *
             * Avant : le statut passait à « Disponible » sans condition, et AVANT que
             * ce cycle ne soit supprimé — la machine était donc déclarée libre alors
             * qu'un autre cycle pouvait encore y être en incubation (multi-étages).
             */
            if ($incubateur && $incubateur->eggsInIncubation() === 0) {
                $incubateur->update(['status' => 'Disponible']);
            }
        });
    }
}

/* namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class AbortIncubation
{
    public function execute(Incubation $incubation): void
    {
        DB::transaction(function () use ($incubation) {
            
            // 1. [INTÉGRATION STOCK] : Si c'est une erreur de saisie, on rend les œufs. 
            // Si c'est une casse machine, on les passe en "pertes".
            // app(StockIntegrationService::class)->restoreOrDestroyIncubableEggs($incubation->eggs_count);

            // 2. Suppression (Le statut de la machine sera libéré par l'IncubationObserver)
            $incubation->delete();
        });
    }
} */