<?php

namespace App\Actions\Sale;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Stock;
use App\Models\Batch;
use App\Models\Payment;
use App\Services\SaleNumberingService;
use App\Services\StockIntegrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateSale
{
    /**
     * Crée une vente complète avec ses lignes.
     *
     * @param array $data   Données validées (client_id, sale_date, type, items[], etc.)
     * @return Sale
     */
    public function execute(array $data): Sale
    {
        return DB::transaction(function () use ($data) {

            // ─── 1. CRÉER LA VENTE ───
            $sale = Sale::create([
                // uuid fourni lors d'une synchro hors-ligne (idempotence) ; sinon
                // le trait HasStandardUuid en génère un automatiquement.
                'uuid'             => $data['uuid'] ?? null,
                'reference'        => SaleNumberingService::generate($data['type'] ?? 'bon_livraison'),
                'client_id'        => $data['client_id'],
                'user_id'          => Auth::id(),
                'sale_date'        => $data['sale_date'],
                'type'             => $data['type'] ?? 'bon_livraison',
                'status'           => 'brouillon',
                'discount_type'    => $data['discount_type'] ?? 'none',
                'discount_value'   => $data['discount_value'] ?? 0,
                'tax_rate'         => $data['tax_rate'] ?? 0,
                'delivery_mode'    => $data['delivery_mode'] ?? 'sur_place',
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_notes'   => $data['delivery_notes'] ?? null,
                'notes'            => $data['notes'] ?? null,
            ]);

            // ─── 2. CRÉER LES LIGNES ───
            foreach ($data['items'] as $item) {
                $total = round((float) $item['quantity'] * (float) $item['unit_price'], 2);

                SaleItem::create([
                    'sale_id'      => $sale->id,
                    'product_type' => $item['product_type'],
                    'product_name' => $item['product_name'],
                    'product_id'   => $item['product_id'] ?? null,
                    'product_ref_id' => $item['product_ref_id'] ?? null,
                    'batch_id'     => $item['batch_id'] ?? null,
                    'quantity'     => $item['quantity'],
                    'unit'         => $item['unit'],
                    'unit_price'   => $item['unit_price'],
                    'total'        => $total,
                ]);
            }

            // ─── 3. CALCULER LES TOTAUX ───
            $sale->recalculateTotals();

            // ─── 4. PAIEMENT IMMÉDIAT (si cash) ───
            if (! empty($data['immediate_payment']) && $data['immediate_payment'] > 0) {
                Payment::create([
                    'sale_id'      => $sale->id,
                    'amount'       => $data['immediate_payment'],
                    'payment_date' => $data['sale_date'],
                    'method'       => $data['payment_method'] ?? 'especes',
                    'reference'    => $data['payment_reference'] ?? null,
                    'received_by'  => Auth::id(),
                    'notes'        => 'Paiement à la vente',
                ]);
                $sale->refreshPaymentStatus();
            }

            Log::info("Vente créée : {$sale->reference} — Client: {$sale->client->name} — Total: {$sale->total_amount} GNF");

            return $sale->fresh(['items', 'client', 'payments']);
        });
    }
}
