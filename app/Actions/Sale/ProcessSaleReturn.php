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

            // Sous-total AVANT reprise, lu sur les lignes — c'est la référence
            // du prorata de remise appliqué après la boucle.
            $subtotalAvant = (float) $sale->items()->sum('total');

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

                // 1. Restocker l'article rendu, sur l'article D'OÙ il est sorti
                // (SaleItem::resolveStock — même résolution que la validation
                // et que l'annulation).
                if ($item->requiresDestock()) {
                    $stock = $item->resolveStock();

                    if (! $stock) {
                        // Le retour reste enregistré (l'avoir est dû au client),
                        // mais l'échec de remise en stock cesse d'être muet.
                        Log::warning(
                            "Retour {$return->reference} : aucun article de stock ne correspond à "
                            . "« {$item->product_name} » — remise en stock impossible, à corriger à la main."
                        );
                    } else {
                        StockIntegrationService::syncMovement(
                            $stock->item_name,
                            $stock->category,
                            $qty,
                            'in',
                            "Retour vente {$sale->reference} ({$return->reference})",
                            $item->stockInputUnit()
                        );
                    }
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
                    // CATÉGORIE et LOT figés ici, pendant qu'ils existent encore :
                    // la ligne de vente est supprimée juste en dessous si le
                    // retour est total, et `sale_items` n'a pas de suppression
                    // douce. Ce sont les deux clefs par lesquelles les rapports
                    // ventilent le chiffre d'affaires.
                    'product_type'   => $item->product_type,
                    'batch_id'       => $item->batch_id,
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

            /*
             * 4. PRORATER LA REMISE EN FRANCS AVANT DE RECALCULER.
             *
             * `recalculateTotals()` réapplique `computeDiscount()`, qui rend la
             * remise TELLE QUELLE quand elle est en francs (`amount`). Une
             * remise consentie sur la commande entière était donc re-déduite en
             * entier d'un sous-total réduit : le client rendait la marchandise
             * et gardait toute la remise.
             *
             * Mesuré, sur 100 000 GNF de marchandise remisés de 20 000 :
             *
             *   • moitié rendue → total 30 000 au lieu de 40 000 ;
             *   • 9 sur 10 rendus → sous-total 10 000, remise plafonnée à
             *     10 000 : TOTAL ZÉRO. Le reste de la commande devenait gratuit,
             *     et le remboursement égalait tout ce qui avait été encaissé.
             *
             * La remise en POURCENTAGE, elle, se proratait déjà d'elle-même
             * (40 000 sur le même retour, ce qu'il faut) : les deux types de
             * remise répondaient différemment au même geste.
             *
             * On aligne donc les francs sur le pourcentage — la remise suit la
             * marchandise conservée. C'est la règle des avoirs : une reprise
             * partielle reprend sa part de remise, elle ne la concentre pas sur
             * ce qui reste.
             *
             * Écrit sur `discount_value` (et non contourné dans le calcul) pour
             * que la vente PORTE la remise qu'elle applique — donc que des
             * reprises successives se composent correctement, chacune sur l'état
             * courant.
             */
            $subtotalApres = (float) $sale->items()->sum('total');

            if ($sale->discount_type === 'amount'
                && (float) $sale->discount_value > 0
                && $subtotalAvant > 0) {
                $sale->forceFill([
                    'discount_value' => round(
                        (float) $sale->discount_value * ($subtotalApres / $subtotalAvant),
                        2
                    ),
                ])->save();
            }

            // 5. Recalculer la vente (total = biens conservés).
            $sale->recalculateTotals();
            $sale->refresh();

            // 6. Rembourser le trop-perçu via un paiement NÉGATIF.
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
