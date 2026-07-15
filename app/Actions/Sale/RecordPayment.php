<?php

namespace App\Actions\Sale;

use App\Models\Payment;
use App\Models\Sale;
use App\Services\NotificationHub;
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
        return DB::transaction(function () use ($sale, $data) {
            // AUDIT C3 (prouvé par drill parallèle) : re-résoudre la vente SOUS
            // verrou — les contrôles ci-dessous, faits hors transaction sur une
            // vente lue sans verrou, laissaient passer deux encaissements
            // concurrents (120 000 acceptés sur 100 000 dus). Le verrou de la
            // ligne vente sérialise le contrôle du reste dû.
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);

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

            $payment = Payment::create([
                'sale_id'      => $sale->id,
                'amount'       => $amount,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'method'       => $data['method'] ?? 'especes',
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
                'reference'    => $data['reference'] ?? null,
                'received_by'  => Auth::id(),
                'notes'        => $data['notes'] ?? null,
            ]);

            // Mettre à jour le statut de paiement de la vente
            $sale->refreshPaymentStatus();

            // Recalculer le solde client
            $sale->client->recalculateBalance();

            // Visibilité admin/propriétaire (hors site) sur chaque encaissement —
            // pièce centrale de la prévention des malversations sur les paiements.
            app(NotificationHub::class)->notifyPaymentReceived($payment);

            return $payment;
        });
    }
}
