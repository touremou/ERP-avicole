<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /**
     * Génère la paie pour une période donnée.
     * Crée une fiche par employé actif avec calcul automatique.
     */
    public function generatePayroll(PayrollPeriod $period): array
    {
        $employees = Employee::where('status', 'Actif')->get();
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($period, $employees, &$created, &$skipped) {
            foreach ($employees as $emp) {
                // Vérifier si la fiche existe déjà
                $exists = Payslip::where('payroll_period_id', $period->id)
                    ->where('employee_id', $emp->id)
                    ->exists();

                if ($exists) { $skipped++; continue; }

                // Calculer les jours
                $totalDays = $period->start_date->diffInDays($period->end_date) + 1;
                $weekends = $this->countWeekends($period->start_date, $period->end_date);
                $workingDays = $totalDays - $weekends;

                // Congés/absences pendant la période
                $leaves = EmployeeLeave::where('employee_id', $emp->id)
                    ->whereIn('status', ['approuve', 'en_cours', 'termine'])
                    ->where('start_date', '<=', $period->end_date)
                    ->where('end_date', '>=', $period->start_date)
                    ->get();

                $daysLeave = 0;
                $daysAbsent = 0;
                $unpaidDays = 0;

                foreach ($leaves as $leave) {
                    $overlapStart = max($leave->start_date->timestamp, $period->start_date->timestamp);
                    $overlapEnd = min($leave->end_date->timestamp, $period->end_date->timestamp);
                    $overlapDays = max(0, Carbon::createFromTimestamp($overlapEnd)->diffInDays(Carbon::createFromTimestamp($overlapStart)) + 1);

                    if (in_array($leave->type, ['conge_annuel', 'maladie', 'maternite', 'formation'])) {
                        $daysLeave += $overlapDays;
                    } else {
                        $daysAbsent += $overlapDays;
                        if (in_array($leave->type, ['sans_solde', 'absence'])) {
                            $unpaidDays += $overlapDays;
                        }
                    }
                }

                $daysWorked = max(0, $workingDays - $daysLeave - $daysAbsent);

                // Créer la fiche
                $payslip = Payslip::create([
                    'payroll_period_id' => $period->id,
                    'employee_id'       => $emp->id,
                    'base_salary'       => (int) $emp->salary,
                    'days_worked'       => $daysWorked,
                    'days_absent'       => $daysAbsent,
                    'days_leave'        => $daysLeave,
                    'overtime_hours'    => 0,
                    'payment_method'    => $emp->orange_money_number ? 'orange_money' : 'especes',
                    'payment_status'    => 'en_attente',
                ]);

                // Déduction pour absences non payées
                if ($unpaidDays > 0 && $workingDays > 0) {
                    // On arrondit le MONTANT TOTAL de la déduction (et non le taux
                    // journalier) pour ne pas accumuler d'erreur de troncature.
                    $deduction = (int) round($emp->salary / $workingDays * $unpaidDays);
                    PayslipLine::create([
                        'payslip_id' => $payslip->id,
                        'type'       => 'deduction',
                        'label'      => "Absence non payée ({$unpaidDays}j)",
                        'amount'     => $deduction,
                        'category'   => 'absence',
                    ]);
                }

                // Recalculer le net
                $payslip->recalculate();
                $created++;
            }
        });

        // Recalculer les totaux de la période
        $period->recalculateTotals();
        $period->update(['status' => 'calcule']);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Compte les dimanches dans une période.
     */
    private function countWeekends(Carbon $start, Carbon $end): int
    {
        $count = 0;
        $current = $start->copy();
        while ($current->lte($end)) {
            $restDay = setting('rh.rest_day', 'dimanche');
            $isRest = match($restDay) {
                'dimanche' => $current->isSunday(),
                'samedi'   => $current->isSaturday(),
                'vendredi' => $current->isFriday(),
                'aucun'    => false,
                default    => $current->isSunday(),
            };
            if ($isRest) $count++;
            $current->addDay();
        }
        return $count;
    }
}
