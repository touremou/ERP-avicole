<?php

namespace App\Actions\FeedPurchase;

use App\Models\FeedPurchase;
use App\Models\Batch;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Exception;

class CreateFeedPurchase
{
    public function execute(array $data): FeedPurchase
    {
        return DB::transaction(function () use ($data) {
            $batch = Batch::findOrFail($data['batch_id']);
            $metadata = $data['metadata'] ?? [];
            $consoType = $metadata['conso_type'] ?? 'Aliment';

            // 1. GESTION DYNAMIQUE DU RÉFÉRENTIEL STOCK
            $stockRef = trim($data['feed_type']);
            $stockItem = Stock::firstOrCreate(
                ['item_name' => $stockRef, 'category' => 'conso'],
                [
                    'feed_type'        => $stockRef,
                    'unit'             => ($data['unit'] === 'Sac') ? 'KG' : $data['unit'],
                    'current_quantity' => 0,
                    'alert_threshold'  => 100,
                    'metadata'         => [
                        'poultry_type' => $metadata['poultry_type'] ?? $batch->type,
                        'conso_type'   => $consoType,
                        'supplier'     => $data['supplier'] ?? null
                    ]
                ]
            );
            // Backfill pour les enregistrements antérieurs sans feed_type
            if (! $stockItem->feed_type) {
                $stockItem->update(['feed_type' => $stockRef]);
            }

            // 2. ENREGISTREMENT DE LA TRANSACTION FINANCIÈRE
            $realUnitPrice = $data['unit_price'] / max($data['quantity'], 1);

            $purchase = FeedPurchase::create(array_merge($data, [
                'unit_price'  => $realUnitPrice,
                'total_price' => $data['unit_price'],
            ]));

            // 3. SYNCHRONISATION PHYSIQUE DU MAGASIN (valorisée au prix d'achat)
            // Coût par unité PIVOT (KG) : prix total ÷ quantité normalisée, afin
            // que le CMP de l'article reflète le coût réel d'acquisition.
            $bagWeight       = (float) ($metadata['bag_weight'] ?? 50);
            $normalizedQty   = ($data['unit'] === 'Sac')
                ? (float) $data['quantity'] * $bagWeight
                : (float) $data['quantity'];
            $costPerPivotKg  = $normalizedQty > 0
                ? (float) $purchase->total_price / $normalizedQty
                : 0.0;

            $synced = StockIntegrationService::syncMovement(
                $purchase->feed_type,
                'conso',
                (float)$data['quantity'],
                'in',
                "Ravitaillement {$data['unit']} - Lot {$batch->code} ({$consoType})",
                $data['unit'],
                $costPerPivotKg
            );

            if (!$synced) {
                throw new Exception("Désaccord critique entre le mouvement et l'état de l'inventaire.");
            }

            // On charge la relation batch pour générer un message de succès précis dans le controller
            return $purchase->load('batch');
        });
    }
}