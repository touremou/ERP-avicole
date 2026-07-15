<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * BudgetMonitor — surveille le franchissement des budgets mensuels.
 *
 * Appelé quand une dépense devient « valide » : si le cumul validé du poste
 * (mois de la dépense) vient de DÉPASSER le budget alloué, déclenche une alerte.
 * La détection au franchissement (cumul d'avant ≤ budget < cumul d'après) rend
 * l'opération idempotente : une fois le poste en dépassement, les dépenses
 * suivantes ne ré-alertent plus.
 */
class BudgetMonitor
{
    public function __construct(private NotificationHub $hub) {}

    /** Vérifie le poste de la dépense et alerte si le budget vient d'être franchi. */
    public function checkOverrun(Expense $expense): void
    {
        if ($expense->status !== 'valide' || ! $expense->expense_date || ! $expense->category) {
            return;
        }

        $date  = $expense->expense_date instanceof Carbon
            ? $expense->expense_date
            : Carbon::parse($expense->expense_date);
        $year  = (int) $date->year;
        $month = (int) $date->month;
        $farmId = $expense->farm_id;

        // Budget alloué au poste pour ce mois (sans budget → pas de dépassement possible).
        $budget = (float) Budget::forFarm($farmId)
            ->forPeriod($year, $month)
            ->where('category', $expense->category)
            ->value('amount');

        if ($budget <= 0) {
            return;
        }

        $from = $date->copy()->startOfMonth()->toDateString();
        $to   = $date->copy()->endOfMonth()->toDateString();

        $spent = (float) Expense::forFarm($farmId)
            ->validated()
            ->where('category', $expense->category)
            ->betweenDates($from, $to)
            ->sum('amount');

        // Contribution de CETTE dépense : on ne déclenche qu'au franchissement.
        $priorSpent = $spent - (float) $expense->amount;

        if ($priorSpent <= $budget && $spent > $budget) {
            // Une défaillance de notification ne doit jamais casser l'enregistrement
            // d'une dépense : on isole l'envoi.
            rescue(
                fn () => $this->hub->alertBudgetOverrun($expense->category, $year, $month, $spent, $budget),
                fn ($e) => Log::warning("Alerte dépassement budget non émise : {$e->getMessage()}"),
                report: false
            );
        }
    }
}
