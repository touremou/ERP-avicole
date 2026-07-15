<?php

namespace App\Services;

use App\Models\TreasuryAccount;
use App\Models\TreasuryTransaction;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TreasuryService — mouvements de trésorerie (entrée/sortie/transfert).
 *
 * Chaque mouvement crée une écriture au grand-livre ET met à jour le solde du
 * compte, de façon atomique. Un transfert = une sortie + une entrée appariées.
 */
class TreasuryService
{
    /** Enregistre un mouvement (in|out) et met à jour le solde du compte. */
    public function record(TreasuryAccount $account, string $direction, float $amount, array $opts = []): TreasuryTransaction
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new Exception('Sens de mouvement invalide.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new Exception('Le montant doit être positif.');
        }

        return DB::transaction(function () use ($account, $direction, $amount, $opts) {
            // Lien polymorphe optionnel vers la pièce d'origine (Payment, Expense…).
            $source = $opts['source'] ?? null;

            $tx = TreasuryTransaction::create([
                'farm_id'                => $account->farm_id,
                'treasury_account_id'    => $account->id,
                'direction'              => $direction,
                'amount'                 => $amount,
                'transaction_date'       => $opts['date'] ?? now()->toDateString(),
                'category'               => $opts['category'] ?? 'manuel',
                'description'            => $opts['description'] ?? null,
                'reference'              => $opts['reference'] ?? null,
                'counterpart_account_id' => $opts['counterpart_account_id'] ?? null,
                'user_id'                => Auth::id(),
                'source_type'            => $source ? $source->getMorphClass() : ($opts['source_type'] ?? null),
                'source_id'              => $source?->getKey() ?? ($opts['source_id'] ?? null),
            ]);

            $account->increment('current_balance', $direction === 'in' ? $amount : -$amount);

            return $tx;
        });
    }

    /** Transfert entre deux comptes (dépôt espèces→banque, etc.). */
    public function transfer(TreasuryAccount $from, TreasuryAccount $to, float $amount, array $opts = []): void
    {
        if ($from->id === $to->id) {
            throw new Exception('Le compte source et le compte destination doivent être différents.');
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new Exception('Le montant du transfert doit être positif.');
        }
        if ($amount > (float) $from->current_balance) {
            throw new Exception("Solde insuffisant sur « {$from->name} ».");
        }

        DB::transaction(function () use ($from, $to, $amount, $opts) {
            $ref  = $opts['reference'] ?? ('TRF-' . now()->format('YmdHis'));
            $desc = $opts['description'] ?? "Transfert {$from->name} → {$to->name}";
            $date = $opts['date'] ?? now()->toDateString();

            $this->record($from, 'out', $amount, [
                'category' => 'transfert', 'description' => $desc, 'reference' => $ref,
                'counterpart_account_id' => $to->id, 'date' => $date,
            ]);
            $this->record($to, 'in', $amount, [
                'category' => 'transfert', 'description' => $desc, 'reference' => $ref,
                'counterpart_account_id' => $from->id, 'date' => $date,
            ]);
        });
    }
}
