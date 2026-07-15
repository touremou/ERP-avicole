<?php

namespace App\Observers;

use App\Models\Expense;
use App\Services\BudgetMonitor;
use App\Services\TreasuryPostingService;

/**
 * ExpenseObserver — surveillance budgétaire ET intégration trésorerie.
 *
 * Budget : dès qu'une dépense VALIDE est créée / change de montant / statut, on
 * vérifie le dépassement du budget mensuel (BudgetMonitor).
 *
 * Trésorerie : une dépense sort l'argent du compte À LA VALIDATION (et non en
 * attente) ; un retour en attente ou une annulation/suppression contre-passe.
 * Idempotent et réversible via TreasuryPostingService (lien polymorphe).
 */
class ExpenseObserver
{
    public function saved(Expense $expense): void
    {
        $posting = app(TreasuryPostingService::class);

        // Décaissement à la validation ; contre-passation si la dépense quitte
        // l'état « valide » (retour en attente, annulation).
        if ($expense->wasRecentlyCreated || $expense->wasChanged('status') || $expense->wasChanged('amount')) {
            if ($expense->status === 'valide') {
                // Un changement de montant sur une dépense déjà comptabilisée :
                // on contre-passe puis on re-poste au nouveau montant.
                if ($expense->wasChanged('amount') && ! $expense->wasRecentlyCreated) {
                    $posting->reverseFor($expense);
                }
                $posting->postExpense($expense);
            } else {
                $posting->reverseFor($expense);
            }
        }

        if ($expense->status !== 'valide') {
            return;
        }

        if (! $expense->wasRecentlyCreated
            && ! $expense->wasChanged('status')
            && ! $expense->wasChanged('amount')) {
            return;
        }

        app(BudgetMonitor::class)->checkOverrun($expense);
    }

    public function deleted(Expense $expense): void
    {
        app(TreasuryPostingService::class)->reverseFor($expense);
    }
}
