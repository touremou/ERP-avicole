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