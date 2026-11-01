<?php

namespace App\Actions\FeedPurchase;

use App\Models\FeedPurchase;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

class DeleteFeedPurchase
{
    public function execute(FeedPurchase $feedPurchase): void
    {
        DB::transaction(function () use ($feedPurchase) {
            $lot = $feedPurchase->batch;
            // Clôture définitive = verrou d'imputation (Batch::accepteImputation) :
            // un lot actif ou soldé reçoit encore ses derniers coûts, pas après.
            if ($lot && $motif = $lot->motifRefusImputation()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['batch_id' => $motif]);
            }
            // Annulation logistique
            StockIntegrationService::syncMovement(
                $feedPurchase->feed_type, 
                'conso', 
                $feedPurchase->quantity, 
                'out', 
                "ANNULATION ACHAT #{$feedPurchase->id}"
            );

            $feedPurchase->delete();
        });
    }
}