<?php

namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordMirage
{
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            if ($incubation->status === 'clos') {
                throw new \DomainException("Impossible d'effectuer un mirage sur un cycle clôturé.");
            }

            $fertile = (int) $data['fertile_eggs'];
            $rate = $incubation->eggs_count > 0 ? ($fertile / $incubation->eggs_count) * 100 : 0;

            $incubation->update([
                'fertile_eggs'   => $fertile,
                'fertility_rate' => $rate,
                'status'         => 'mirage_fait',
            ]);

            return $incubation->fresh();
        });
    }
}

/* namespace App\Actions\Incubation;

use App\Models\Incubation;
use Illuminate\Support\Facades\DB;

class RecordMirage
{
    
     //* Enregistre les résultats du mirage et met à jour le statut du cycle.
     
    public function execute(Incubation $incubation, array $data): Incubation
    {
        return DB::transaction(function () use ($incubation, $data) {
            
            // Verrou de sécurité métier
            if ($incubation->status === 'clos') {
                throw new \DomainException("Impossible d'effectuer un mirage sur un cycle clôturé.");
            }
            // Dans RecordMirage.php
            $fertile = (int) $data['fertile_eggs'];
            $rate = $incubation->eggs_count > 0 ? ($fertile / $incubation->eggs_count) * 100 : 0;

            $incubation->update([
                'fertile_eggs'   => $fertile,
                'fertility_rate' => $rate, // 💡 AJOUT CRUCIAL ICI
                'status'         => 'mirage_fait',
            ]);

            // Note pour l'avenir : Si les œufs clairs (non-fertiles) sont revendus ou détruits,
            // c'est ICI que nous appellerons le StockIntegrationService pour ajuster le stock.
            // $clearEggs = $incubation->eggs_count - $incubation->fertile_eggs;
            // app(StockIntegrationService::class)->registerClearEggsLoss($clearEggs);

            return $incubation->fresh();
        });
    }
} */