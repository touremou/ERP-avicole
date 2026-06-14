<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\Log;

/**
 * Annule une dépense → « annule ». Elle est exclue des résultats financiers
 * mais conservée pour la traçabilité (audit). Une dépense déjà annulée est
 * laissée en l'état (idempotent).
 */
class CancelExpense
{
    public function execute(Expense $expense, ?string $reason = null): Expense
    {
        if ($expense->status === 'annule') {
            return $expense;
        }

        $note = $expense->notes;
        if ($reason) {
            $note = trim(($note ? $note . "\n" : '') . "[Annulation] " . $reason);
        }

        $expense->update([
            'status' => 'annule',
            'notes'  => $note,
        ]);

        Log::info("Dépense annulée : {$expense->reference}.");

        return $expense;
    }
}
