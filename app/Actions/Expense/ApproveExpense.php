<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Valide une dépense « en_attente » → « valide ».
 * Dès cet instant, elle entre dans les résultats financiers (P&L + marge lot).
 */
class ApproveExpense
{
    public function execute(Expense $expense): Expense
    {
        if ($expense->status !== 'en_attente') {
            throw new \RuntimeException("Seule une dépense en attente peut être validée.");
        }

        $expense->update([
            'status'      => 'valide',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        Log::info("Dépense validée : {$expense->reference} par user " . Auth::id());

        return $expense;
    }
}
