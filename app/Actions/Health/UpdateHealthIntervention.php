<?php

namespace App\Actions\Health;

use App\Models\HealthCheck;
use Illuminate\Support\Facades\DB;

class UpdateHealthIntervention
{
    public function execute(HealthCheck $healthCheck, array $data): HealthCheck
    {
        return DB::transaction(function () use ($healthCheck, $data) {
            
            // [INTÉGRATION FUTURE]
            // Si le coût ou le nom du produit change, c'est ici que l'on calcule 
            // la différence (le delta) pour réajuster le stock ou la comptabilité :
            // $costDifference = $data['cost'] - $healthCheck->cost;
            // app(AccountingService::class)->adjustHealthCost($healthCheck->batch_id, $costDifference);

            $healthCheck->update($data);

            return $healthCheck->fresh();
        });
    }
}