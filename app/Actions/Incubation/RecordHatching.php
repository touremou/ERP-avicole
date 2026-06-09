<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordHatching
{
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            $hatched = (int) $data['hatched_chicks'];
            $fertile = (int) $incubation->fertile_eggs;
            
            $hatchabilityRate = $fertile > 0 ? ($hatched / $fertile) * 100 : 0;

            $incubation->update([
                'hatched_chicks'    => $hatched,
                'hatchability_rate' => $hatchabilityRate,
                'status'            => 'clos',
                'finished_at'       => now()
            ]);

            if ($incubation->incubator) {
                $incubation->incubator->update(['status' => 'Maintenance']);
            }

            return $incubation->fresh();
        });
    }
}



/* namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordHatching
{
    
     //* Exécute l'enregistrement de l'éclosion et clôture le cycle.
     
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            $hatched = (int) $data['hatched_chicks'];
            $fertile = (int) $incubation->fertile_eggs;
            
            // Calcul du taux d'éclosabilité
            $hatchabilityRate = $fertile > 0 ? ($hatched / $fertile) * 100 : 0;

            // 1. Clôture de l'incubation
            $incubation->update([
                'hatched_chicks'    => $hatched,
                'status'            => 'clos' // Le taux sera probablement un accessoire ou géré par un Observer plus tard
            ]);

            // 2. Gestion de l'infrastructure (Machine)
            if ($incubation->incubator) {
                // Rigueur : Une machine sortant d'un cycle DOIT passer par un nettoyage/désinfection
                $incubation->incubator->update(['status' => 'Maintenance']);
            }

            // [OPTIONNEL FUTUR] 3. Intégration Stock : Créer automatiquement le lot de poussins ou l'entrée en stock
            // app(StockIntegrationService::class)->addChicksToStock($hatched, $incubation->batch_id);

            return $incubation->fresh();
        });
    }
} */