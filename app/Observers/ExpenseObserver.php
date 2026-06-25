<?php

namespace App\Observers;

use App\Models\Expense;
use App\Services\BudgetMonitor;

/**
 * ExpenseObserver — branche la surveillance budgétaire sur le cycle de vie des
 * dépenses. Dès qu'une dépense VALIDE est créée ou que son montant/statut change,
 * on vérifie si son poste vient de dépasser le budget mensuel (cf. BudgetMonitor).
 *
 * Couvre tous les chemins de création de dépense valide : validation manuelle
 * (ApproveExpense) ET dépense carburant auto-postée (FuelPurchase).
 */
class ExpenseObserver
{
    public function saved(Expense $expense): void
    {
        if ($expense->status !== 'valide') {
            return;
        }

        // On ne recalcule qu'aux changements pertinents (création, passage à
        // « valide », ajustement de montant) pour éviter un calcul à chaque save.
        if (! $expense->wasRecentlyCreated
            && ! $expense->wasChanged('status')
            && ! $expense->wasChanged('amount')) {
            return;
        }

        app(BudgetMonitor::class)->checkOverrun($expense);
    }
}
