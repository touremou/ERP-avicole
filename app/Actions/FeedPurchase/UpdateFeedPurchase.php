<?php

namespace App\Actions\FeedPurchase;

use App\Models\FeedPurchase;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;

class UpdateFeedPurchase
{
    public function execute(FeedPurchase $feedPurchase, array $data): FeedPurchase
    {
        return DB::transaction(function () use ($feedPurchase, $data) {
            $oldQuantity = (float) $feedPurchase->quantity;
            $newQuantity = (float) $data['quantity'];
            $oldType     = $feedPurchase->feed_type;
            $newType     = $data['feed_type'];

            // 1. RÉGULARISATION PAR DELTA (Mouvement net)
            if ($oldType !== $newType) {
                StockIntegrationService::syncMovement($oldType, 'conso', $oldQuantity, 'out', "Rectification Type (Annulation)");
                StockIntegrationService::syncMovement($newType, 'conso', $newQuantity, 'in', "Rectification Type (Nouveau)");
            } else {
                $diff = $newQuantity - $oldQuantity;
                if ($diff != 0) {
                    StockIntegrationService::syncMovement($newType, 'conso', abs($diff), $diff > 0 ? 'in' : 'out', "Ajustement quantité achat");
                }
            }

            // 2. UPDATE COMPTABLE
            $feedPurchase->update([
                'feed_type'     => $data['feed_type'],
                'quantity'      => $data['quantity'],
                'unit_price'    => $data['unit_price'] / max($data['quantity'], 1),
                'total_price'   => $data['unit_price'],
                'supplier'      => $data['supplier'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'metadata'      => array_merge($feedPurchase->metadata ?? [], $data['metadata'] ?? [])
            ]);

            return $feedPurchase;
        });
    }
}