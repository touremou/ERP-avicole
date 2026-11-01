<?php

namespace App\Actions\Health;

use App\Models\Batch;
use App\Models\HealthCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordHealthIntervention
{
    public function execute(array $data): HealthCheck
    {
        // Date dans la vie du lot (Batch::refusDeSaisie) : la requête web
        // l'exigeait, le terrain non. Lot fermé admis, comme au bureau.
        $batch = Batch::findOrFail($data['batch_id']);
        if ($refus = $batch->refusDeSaisie($data['intervention_date'], 'intervention_date', lotOuvert: false)) {
            throw ValidationException::withMessages($refus);
        }

        return DB::transaction(function () use ($data) {

            $healthCheck = HealthCheck::create($data);

            // [INTÉGRATION STOCK FUTURE]
            // app(StockIntegrationService::class)->deductVeterinaryProduct($data['product_name'], $data['batch_id']);

            return $healthCheck;
        });
    }
}