<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TreasuryPostingService — comptabilise automatiquement les flux financiers
 * dans la trésorerie, de façon idempotente et réversible.
 *
 *  - Encaissement de vente (Payment) → ENTRÉE sur le compte du mode de paiement.
 *    Un paiement négatif (remboursement / avoir) → SORTIE.
 *  - Dépense validée (Expense) → SORTIE sur le compte du mode de paiement.
 *
 * Le compte cible suit le mapping mode→compte (TreasuryAccount::resolveForMethod),
 * sauf override explicite (source.treasury_account_id). La trésorerie reste
 * OPTIONNELLE : sans compte configuré, on ne fait rien (log informatif).
 */
class TreasuryPostingService
{
    public function __construct(private TreasuryService $service) {}

    /** Comptabilise l'encaissement d'un paiement de vente (entrée, ou sortie si remboursement). */
    public function postPayment(Payment $payment): ?TreasuryTransaction
    {
        if ($this->alreadyPosted($payment)) {
            return null;
        }

        $amount = (float) $payment->amount;
        if ($amount == 0.0) {
            return null;
        }

        $account = $this->resolveAccount($payment->treasury_account_id, $payment->method);
        if (! $account) {
            Log::info("Trésorerie : aucun compte pour le paiement #{$payment->id} ({$payment->method}) — non comptabilisé.");
            return null;
        }

        $direction = $amount >= 0 ? 'in' : 'out';
        $ref = $payment->sale?->reference;

        return $this->service->record($account, $direction, abs($amount), [
            'date'        => optional($payment->payment_date)->toDateString() ?? now()->toDateString(),
            'category'    => $amount >= 0 ? 'vente' : 'remboursement',
            'description' => ($amount >= 0 ? 'Encaissement vente' : 'Remboursement vente') . ($ref ? " {$ref}" : ''),
            'reference'   => $payment->reference ?: $ref,
            'source'      => $payment,
        ]);
    }

    /** Comptabilise une dépense VALIDÉE (sortie). Ignorée tant qu'elle n'est pas validée. */
    public function postExpense(Expense $expense): ?TreasuryTransaction
    {
        if ($expense->status !== 'valide' || $this->alreadyPosted($expense)) {
            return null;
        }

        $amount = (float) $expense->amount;
        if ($amount <= 0) {
            return null;
        }

        $account = $this->resolveAccount($expense->treasury_account_id, $expense->payment_method ?? 'especes');
        if (! $account) {
            Log::info("Trésorerie : aucun compte pour la dépense #{$expense->id} — non comptabilisée.");
            return null;
        }

        return $this->service->record($account, 'out', $amount, [
            'date'        => optional($expense->expense_date)->toDateString() ?? now()->toDateString(),
            'category'    => 'depense',
            'description' => 'Dépense ' . ($expense->label ?? $expense->reference ?? ''),
            'reference'   => $expense->reference,
            'source'      => $expense,
        ]);
    }

    /**
     * Contre-passe toutes les écritures générées par une pièce (annulation,
     * suppression, remboursement) : restaure le solde et supprime l'écriture.
     */
    public function reverseFor(Model $source): void
    {
        $txs = TreasuryTransaction::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get();

        if ($txs->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($txs) {
            foreach ($txs as $tx) {
                // Restaurer le solde du compte du delta signé de l'écriture, puis la supprimer.
                $tx->account?->increment('current_balance', $tx->direction === 'in' ? -(float) $tx->amount : (float) $tx->amount);
                $tx->delete();
            }
        });
    }

    /** Une écriture a-t-elle déjà été générée pour cette pièce ? (anti double-comptage) */
    private function alreadyPosted(Model $source): bool
    {
        return TreasuryTransaction::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->exists();
    }

    /** Override explicite, sinon mapping mode→compte. */
    private function resolveAccount(?int $explicitId, ?string $method): ?TreasuryAccount
    {
        if ($explicitId && ($acc = TreasuryAccount::find($explicitId))) {
            return $acc;
        }

        return TreasuryAccount::resolveForMethod($method ?? 'especes');
    }
}
