<?php

namespace App\Actions\Sale;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;

class RecordPayment
{
    /**
     * Enregistre un paiement sur une vente.
     */
    public function execute(Sale $sale, array $data): Payment
    {
        if ($sale->payment_status === 'solde') {
            throw new Exception("La vente {$sale->reference} est déjà soldée.");
        }

        if (in_array($sale->status, ['brouillon', 'annule'])) {
            throw new Exception("Impossible d'encaisser sur une vente {$sale->status}.");
        }

        $amount = (float) $data['amount'];
        $remaining = $sale->remaining_amount;

        if ($amount > $remaining) {
            throw new Exception(
                "Montant trop élevé : {$amount} GNF dépasse le reste dû ({$remaining} GNF)."
            );
        }

        return DB::transaction(function () use ($sale, $data, $amount) {

            $payment = Payment::create([
                'sale_id'      => $sale->id,
                'amount'       => $amount,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'method'       => $data['method'] ?? 'especes',
                'reference'    => $data['reference'] ?? null,
                'received_by'  => Auth::id(),
                'notes'        => $data['notes'] ?? null,
            ]);

            // Mettre à jour le statut de paiement de la vente
            $sale->refreshPaymentStatus();

            // Recalculer le solde client
            $sale->client->recalculateBalance();

            return $payment;
        });
    }
}
