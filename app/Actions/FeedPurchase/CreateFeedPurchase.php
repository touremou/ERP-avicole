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
            Stock::firstOrCreate(
                ['item_name' => trim($data['feed_type']), 'category' => 'conso'],
                [
                    'unit'             => ($data['unit'] === 'Sac') ? 'KG' : $data['unit'],
                    'current_quantity' => 0,
                    'alert_threshold'  => 100, // Seuil de sécurité par défaut
                    'metadata'         => [
                        'poultry_type' => $metadata['poultry_type'] ?? $batch->type,
                        'conso_type'   => $consoType,
                        'supplier'     => $data['supplier'] ?? null
                    ]
                ]
            );

            // 2. ENREGISTREMENT DE LA TRANSACTION FINANCIÈRE
            $realUnitPrice = $data['unit_price'] / max($data['quantity'], 1);

            $purchase = FeedPurchase::create(array_merge($data, [
                'unit_price'  => $realUnitPrice,
                'total_price' => $data['unit_price'],
            ]));

            // 3. SYNCHRONISATION PHYSIQUE DU MAGASIN
            $synced = StockIntegrationService::syncMovement(
                $purchase->feed_type, 
                'conso', 
                (float)$data['quantity'], 
                'in', 
                "Ravitaillement {$data['unit']} - Lot {$batch->code} ({$consoType})",
                $data['unit'] 
            );

            if (!$synced) {
                throw new Exception("Désaccord critique entre le mouvement et l'état de l'inventaire.");
            }

            // On charge la relation batch pour générer un message de succès précis dans le controller
            return $purchase->load('batch');
        });
    }
}