<?php

namespace App\Actions\FeedPurchase;

use App\Models\FeedPurchase;
use App\Models\Provider;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateFeedPurchase
{
    public function execute(FeedPurchase $feedPurchase, array $data): FeedPurchase
    {
        $feedPurchase = DB::transaction(function () use ($feedPurchase, $data) {
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

        // 3. PROPAGATION AP (post-commit, non bloquant) : la facture fournisseur
        // liée suit le nouveau montant/fournisseur. On PRÉSERVE l'état de paiement
        // (soldé → règlement re-calé sur le nouveau total ; crédit → reste impayé).
        $this->syncSupplierInvoice($feedPurchase, $data);

        return $feedPurchase;
    }

    private function syncSupplierInvoice(FeedPurchase $feedPurchase, array $data): void
    {
        try {
            $invoice = SupplierInvoice::where('feed_purchase_id', $feedPurchase->id)->first();
            if (! $invoice) {
                return; // pas de facture liée (achat sans fournisseur, ou antérieur)
            }

            $wasPaid    = $invoice->payment_status === 'solde';
            $providerId = $invoice->provider_id;
            if (! empty($data['supplier'])) {
                $providerId = Provider::firstOrCreate(
                    ['name' => trim($data['supplier'])],
                    ['type' => 'Aliment', 'phone' => '—', 'status' => 'Actif']
                )->id;
            }

            DB::transaction(function () use ($invoice, $feedPurchase, $data, $wasPaid, $providerId) {
                $invoice->update([
                    'provider_id'  => $providerId,
                    'invoice_date' => $data['purchase_date'],
                    'label'        => 'Aliment — ' . $feedPurchase->feed_type . ' (' . $feedPurchase->display_label . ')',
                    'total_amount' => $feedPurchase->total_price,
                ]);

                // Re-cale le règlement intégral sur le nouveau total (si soldé).
                if ($wasPaid) {
                    $invoice->payments()->delete();
                    SupplierPayment::create([
                        'supplier_invoice_id' => $invoice->id,
                        'amount'              => $feedPurchase->total_price,
                        'payment_date'        => $data['purchase_date'],
                        'method'              => 'especes',
                        'notes'               => "Réglé à l'achat (aliment) — rectifié",
                        'paid_by'             => Auth::id(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('AP feed-purchase update sync échouée: ' . $e->getMessage());
        }
    }
}
