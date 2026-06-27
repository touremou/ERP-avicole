<?php

namespace App\Actions\Sale;

use App\Models\Batch;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Stock;
use App\Services\StockIntegrationService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Traite un retour client : restocke les articles rendus, réduit la vente
 * d'origine, rembourse le trop-perçu (paiement négatif) et garde la trace.
 *
 * Modèle simple et cohérent : la vente reflète toujours les biens CONSERVÉS
 * (total réduit), et l'avoir conserve le détail de ce qui a été rendu. Le
 * remboursement = max(0, déjà payé − nouveau total), pour ne jamais rendre plus
 * que ce que le client avait réglé.
 */
class ProcessSaleReturn
{
    /**
     * @param array<int,float> $lines [ sale_item_id => quantité retournée ]
     */
    public function execute(Sale $sale, array $lines, string $reason = '', string $refundMethod = 'especes'): SaleReturn
    {
        if (! in_array($sale->status, ['valide', 'livre'], true)) {
            throw new Exception("Seule une vente validée ou livrée peut faire l'objet d'un retour.");
        }

        return DB::transaction(function () use ($sale, $lines, $reason, $refundMethod) {
            $paidBefore = (float) $sale->payments()->sum('amount');

            $return = SaleReturn::create([
                'sale_id'       => $sale->id,
                'reference'     => $this->generateReference(),
                'return_date'   => now()->toDateString(),
                'reason'        => $reason !== '' ? $reason : null,
                'refund_method' => $refundMethod,
                'user_id'       => Auth::id(),
            ]);

            $returnedValue = 0.0;

            foreach ($lines as $saleItemId => $qty) {
                $qty = (float) $qty;
                if ($qty <= 0) {
                    continue;
                }

                $item = $sale->items()->find($saleItemId);
                if (! $item) {
                    continue;
                }
                $qty = min($qty, (float) $item->quantity); // jamais plus que vendu

                // 1. Restocker l'article rendu (même mécanique que l'annulation).
                if ($item->requiresDestock()) {
                    StockIntegrationService::syncMovement(
                        $item->product_name,
                        Stock::categoryForProductType($item->product_type),
                        $qty,
                        'in',
                        "Retour vente {$sale->reference} ({$return->reference})",
                        match ($item->unit) {
                            'alveole' => 'Alvéole',
                            'sac'     => 'Sac',
                            'litre'   => 'Litre',
                            'tete'    => 'Tête',
                            default   => 'KG',
                        }
                    );
                }
                if ($item->decrementsBatchCount() && $item->batch_id) {
                    Batch::find($item->batch_id)?->increment('current_quantity', (int) $qty);
                }

                // 2. Trace (snapshot).
                $lineTotal = round($qty * (float) $item->unit_price, 2);
                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'sale_item_id'   => $item->id,
                    'product_name'   => $item->product_name,
                    'quantity'       => $qty,
                    'unit_price'     => $item->unit_price,
                    'total'          => $lineTotal,
                ]);
                $returnedValue += $lineTotal;

                // 3. Réduire (ou retirer) la ligne de vente.
                $newQty = round((float) $item->quantity - $qty, 2);
                if ($newQty <= 0) {
                    $item->delete();
                } else {
                    $item->update([
                        'quantity' => $newQty,
                        'total'    => round($newQty * (float) $item->unit_price, 2),
                    ]);
                }
            }

            // 4. Recalculer la vente (total = biens conservés).
            $sale->recalculateTotals();
            $sale->refresh();

            // 5. Rembourser le trop-perçu via un paiement NÉGATIF.
            $refund = round(max(0, $paidBefore - (float) $sale->total_amount), 2);
            if ($refund > 0) {
                Payment::create([
                    'sale_id'      => $sale->id,
                    'amount'       => -$refund,
                    'payment_date' => now()->toDateString(),
                    'method'       => $refundMethod,
                    'received_by'  => Auth::id(),
                    'notes'        => "Remboursement retour {$return->reference}",
                ]);
            }
            $sale->refreshPaymentStatus();

            $return->update(['total_refund' => $refund]);
            $sale->client->recalculateBalance();

            Log::info("Retour {$return->reference} sur {$sale->reference} — valeur {$returnedValue}, remboursé {$refund}.");

            return $return->fresh('items');
        });
    }

    private function generateReference(): string
    {
        return \App\Services\DocumentNumberingService::generate('sale_return');
    }
}
