<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
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
        $outOfContract = 0;

        DB::transaction(function () use ($period, $employees, &$created, &$skipped, &$outOfContract) {
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

                // Jours réellement SOUS CONTRAT dans la période (embauche en cours
                // de mois, terme de CDD atteint). Aucun jour sous contrat = aucune
                // fiche : mieux vaut ne rien produire qu'un bulletin à zéro, qui
                // ferait croire à un salarié impayé.
                $contract = $this->contractWindow($emp, $period);

                if ($contract['working_days'] <= 0) {
                    $outOfContract++;
                    continue;
                }

                $daysOutsideContract = max(0, $workingDays - $contract['working_days']);

                // Congés/absences pendant la période
                $leaves = EmployeeLeave::where('employee_id', $emp->id)
                    ->whereIn('status', ['approuve', 'en_cours', 'termine'])
                    ->where('start_date', '<=', $contract['end'])
                    ->where('end_date', '>=', $contract['start'])
                    ->get();

                $daysLeave = 0;
                $daysAbsent = 0;
                $unpaidDays = 0;

                foreach ($leaves as $leave) {
                    // Chevauchement borné à la FENÊTRE CONTRACTUELLE : un congé qui
                    // déborde avant l'embauche ou après le terme ne se déduit pas
                    // deux fois (il est déjà hors contrat).
                    $overlapStart = max($leave->start_date->timestamp, $contract['start']->timestamp);
                    $overlapEnd = min($leave->end_date->timestamp, $contract['end']->timestamp);
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

                // Absences RÉELLEMENT pointées (statut « absent ») non justifiées :
                // elles s'ajoutent aux absences et sont déduites (non payées). Les
                // jours NON pointés sont présumés travaillés (bénéfice du doute),
                // pour ne pas pénaliser un pointage incomplet. Les jours de congé
                // sont pré-pointés « conge » (cf. AttendanceController), donc ils ne
                // remontent pas ici en « absent » → pas de double comptage.
                $pointedAbsent = EmployeeAttendance::where('employee_id', $emp->id)
                    ->whereDate('attendance_date', '>=', $contract['start']->toDateString())
                    ->whereDate('attendance_date', '<=', $contract['end']->toDateString())
                    ->where('status', 'absent')
                    ->count();

                $daysAbsent += $pointedAbsent;
                $unpaidDays += $pointedAbsent;

                // Les jours travaillés se comptent DANS la fenêtre contractuelle.
                $daysWorked = max(0, $contract['working_days'] - $daysLeave - $daysAbsent);

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

                // PRORATA D'ENTRÉE / DE SORTIE, porté par une ligne du bulletin
                // plutôt qu'en modifiant le salaire de base : le salarié voit le
                // salaire contractuel et la retenue qui l'explique.
                if ($daysOutsideContract > 0 && $workingDays > 0) {
                    $prorata = (int) round($emp->salary / $workingDays * $daysOutsideContract);
                    $label = $emp->hire_date && $emp->hire_date->gt($period->start_date)
                        ? "Entrée en cours de période ({$daysOutsideContract}j non dus)"
                        : "Fin de contrat en cours de période ({$daysOutsideContract}j non dus)";

                    PayslipLine::create([
                        'payslip_id' => $payslip->id,
                        'type'       => 'deduction',
                        'label'      => $label,
                        'amount'     => $prorata,
                        'category'   => 'prorata_contrat',
                    ]);
                }

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

        return ['created' => $created, 'skipped' => $skipped, 'out_of_contract' => $outOfContract];
    }

    /**
     * Compte les jours de repos hebdomadaire dans une période (cf. rh.rest_day).
     */
    private function countWeekends(Carbon $start, Carbon $end): int
    {
        $count = 0;
        $current = $start->copy();
        $restDay = setting('rh.rest_day', 'dimanche');

        while ($current->lte($end)) {
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

    /** Jours ouvrés d'un intervalle (hors jour de repos hebdomadaire). */
    private function workingDaysBetween(Carbon $start, Carbon $end): int
    {
        if ($start->gt($end)) {
            return 0;
        }

        $total = (int) $start->diffInDays($end) + 1;

        return max(0, $total - $this->countWeekends($start, $end));
    }

    /**
     * FENÊTRE CONTRACTUELLE d'un employé à l'intérieur d'une période de paie.
     *
     * La paie ignorait la date d'EMBAUCHE et la date de FIN DE CONTRAT : un agent
     * recruté le 25 touchait le mois entier, et un CDD arrivé à terme le 10
     * continuait d'être payé en plein tant que sa fiche restait « Actif » — ce que
     * rien n'automatise, et à raison : archiver un dossier est une décision RH.
     * Ces deux dates existent au dossier depuis toujours ; la paie ne les lisait
     * pas.
     *
     * @return array{start: ?Carbon, end: ?Carbon, working_days: int}
     */
    private function contractWindow(Employee $employee, PayrollPeriod $period): array
    {
        $start = $employee->hire_date && $employee->hire_date->gt($period->start_date)
            ? $employee->hire_date->copy()
            : $period->start_date->copy();

        $end = $employee->contract_end_date && $employee->contract_end_date->lt($period->end_date)
            ? $employee->contract_end_date->copy()
            : $period->end_date->copy();

        if ($start->gt($end)) {
            return ['start' => null, 'end' => null, 'working_days' => 0];
        }

        return ['start' => $start, 'end' => $end, 'working_days' => $this->workingDaysBetween($start, $end)];
    }
}
