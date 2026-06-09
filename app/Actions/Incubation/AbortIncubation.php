<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class AbortIncubation
{
    public function execute(Incubation $incubation): void
    {
        DB::transaction(function () use ($incubation) {
            if ($incubation->incubator) {
                $incubation->incubator->update(['status' => 'Disponible']);
            }
            $incubation->delete();
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