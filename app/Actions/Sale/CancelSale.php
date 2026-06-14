<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\Stock;
use App\Models\Batch;
use App\Services\NotificationHub;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CancelSale
{
    /**
     * Annule une vente et restocke si elle était validée.
     */
    public function execute(Sale $sale, string $reason = ''): Sale
    {
        if ($sale->status === 'annule') {
            throw new Exception("La vente {$sale->reference} est déjà annulée.");
        }

        if ($sale->payments()->exists()) {
            throw new Exception(
                "Impossible d'annuler : des paiements sont enregistrés sur {$sale->reference}. " .
                "Effectuez d'abord un remboursement."
            );
        }

        // Statut AVANT annulation (pour l'alerte anti-fraude : une vente
        // validée/livrée annulée est un signal fort, à la différence d'un
        // simple brouillon abandonné).
        $previousStatus = $sale->status;

        return DB::transaction(function () use ($sale, $reason, $previousStatus) {

            // Si la vente était validée, il faut RESTOCKER
            if (in_array($sale->status, ['valide', 'livre'])) {
                foreach ($sale->items as $item) {

                    // Restockage articles
                    if ($item->requiresDestock()) {
                        StockIntegrationService::syncMovement(
                            $item->product_name,
                            Stock::categoryForProductType($item->product_type),
                            (float) $item->quantity,
                            'in',
                            "Annulation vente {$sale->reference} — Restockage",
                            match ($item->unit) {
                                'alveole' => 'Alvéole',
                                'sac'     => 'Sac',
                                'litre'   => 'Litre',
                                'tete'    => 'Tête',
                                default   => 'KG',
                            }
                        );
                    }

                    // Restockage animal vif → ré-incrémenter l'effectif du lot
                    // (uniquement si la vente avait décrémenté l'effectif).
                    if ($item->decrementsBatchCount() && $item->batch_id) {
                        $batch = Batch::find($item->batch_id);
                        if ($batch) {
                            $batch->increment('current_quantity', (int) $item->quantity);
                        }
                    }
                }
            }

            $sale->update([
                'status' => 'annule',
                'notes'  => trim(($sale->notes ?? '') . "\n[ANNULÉ] {$reason}"),
            ]);

            // Recalculer le solde client
            $sale->client->recalculateBalance();

            Log::info("Vente annulée : {$sale->reference} — Raison: {$reason}");

            // Alerte anti-fraude : visibilité immédiate du propriétaire sur
            // toute annulation (surtout d'une vente déjà validée/déstockée).
            app(NotificationHub::class)->alertSaleCancelled($sale->fresh(['client']), $reason, $previousStatus);

            return $sale->fresh();
        });
    }
}
