<?php

namespace App\Actions\Health;

use App\Models\HealthCheck;
use Illuminate\Support\Facades\DB;

class RecordHealthIntervention
{
    public function execute(array $data): HealthCheck
    {
        return DB::transaction(function () use ($data) {
            
            $healthCheck = HealthCheck::create($data);

            // [INTÉGRATION STOCK FUTURE]
            // app(StockIntegrationService::class)->deductVeterinaryProduct($data['product_name'], $data['batch_id']);

            return $healthCheck;
        });
    }
}