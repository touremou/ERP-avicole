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
                // Par le LIEN de l'employé : un congé lui appartient, d'où qu'il
                // ait été saisi. Une requête filtrée par ferme aurait manqué un
                // congé enregistré depuis le site où il est prêté — un sans-solde
                // ne serait pas déduit, un congé payé pas compté.
                $leaves = $emp->leaves()
                    ->whereIn('status', ['approuve', 'en_cours', 'termine'])
                    ->where('start_date', '<=', $contract['end'])
                    ->where('end_date', '>=', $contract['start'])
                    ->get();

                $daysLeave = 0;
                $daysAbsent = 0;
                $unpaidDays = 0;
                $joursDeConge = [];

                foreach ($leaves as $leave) {
                    // Chevauchement borné à la FENÊTRE CONTRACTUELLE : un congé qui
                    // déborde avant l'embauche ou après le terme ne se déduit pas
                    // deux fois (il est déjà hors contrat).
                    /*
                     * AUCUN CONGÉ N'ÉTAIT COMPTÉ — LE CALCUL RENDAIT TOUJOURS ZÉRO.
                     *
                     * La ligne d'origine appelait `$fin->diffInDays($debut)` :
                     * l'écart est SIGNÉ, donc négatif quand on part de la fin.
                     * Cinq jours donnaient -4, +1 faisait -3, et `max(0, …)`
                     * avalait le tout. Le zéro n'était pas une absence de congé :
                     * c'était une soustraction à l'envers, rendue muette par le
                     * garde-fou censé empêcher les valeurs négatives.
                     *
                     * Conséquence, tous les mois et pour tout le monde : les
                     * congés n'entraient pas dans `daysLeave`, les absences
                     * justifiées pas dans `daysAbsent`, et surtout les SANS-SOLDE
                     * pas dans `unpaidDays` — la retenue correspondante ne se
                     * faisait jamais. Un agent en congé sans solde était payé en
                     * entier, et son bulletin affichait « 0 jour d'absence »,
                     * comme s'il avait été présent.
                     *
                     * (Les absences POINTÉES, elles, étaient bien déduites : leur
                     * décompte est un simple `count()`. D'où un défaut invisible —
                     * la paie « marchait ».)
                     *
                     * On écrit donc le sens explicitement, bornes incluses.
                     */
                    $debutChevauchement = Carbon::createFromTimestamp(
                        max($leave->start_date->timestamp, $contract['start']->timestamp)
                    )->startOfDay();

                    $finChevauchement = Carbon::createFromTimestamp(
                        min($leave->end_date->timestamp, $contract['end']->timestamp)
                    )->startOfDay();

                    /*
                     * EN JOURS OUVRÉS — le dimanche n'est pas un jour de congé.
                     *
                     * Ce décompte était CALENDAIRE, alors que la retenue qui en
                     * découle vaut « salaire ÷ jours OUVRÉS × jours décomptés ».
                     * Un congé qui enjambe un jour de repos facturait donc ce
                     * repos au salarié, au taux d'une journée de travail.
                     *
                     * Mesuré : un sans-solde du lundi 3 au lundi 10 août 2026
                     * (8 jours calendaires, 7 ouvrés) retenait 615 385 GNF au
                     * lieu de 538 462 sur un salaire de 2 000 000 — 76 923 GNF
                     * pour un dimanche que personne ne lui payait de travailler.
                     *
                     * `workingDaysBetween()` est la MÊME déclaration que celle
                     * qui produit `workingDays`, le dénominateur de la retenue.
                     * Numérateur et dénominateur comptent désormais la même
                     * chose — c'était la seule façon que le rapport ait un sens.
                     */
                    $overlapDays = $this->workingDaysBetween($debutChevauchement, $finChevauchement);

                    if (in_array($leave->type, ['conge_annuel', 'maladie', 'maternite', 'formation'])) {
                        $daysLeave += $overlapDays;
                    } else {
                        $daysAbsent += $overlapDays;
                        if (in_array($leave->type, ['sans_solde', 'absence'])) {
                            $unpaidDays += $overlapDays;
                        }
                    }

                    // Les jours DÉJÀ décomptés au titre de ce congé, pour ne pas
                    // les recompter au pointage (cf. plus bas).
                    for ($j = $debutChevauchement->copy(); $j->lte($finChevauchement); $j->addDay()) {
                        $joursDeConge[$j->toDateString()] = true;
                    }
                }

                /*
                 * ABSENCES POINTÉES — SAUF LES JOURS DÉJÀ COMPTÉS EN CONGÉ.
                 *
                 * Le commentaire d'origine tenait le raisonnement suivant : « les
                 * jours de congé sont pré-pointés "conge" (cf.
                 * AttendanceController), donc ils ne remontent pas ici en
                 * "absent" → pas de double comptage ».
                 *
                 * Ce pré-pointage n'est qu'une VALEUR PAR DÉFAUT du formulaire.
                 * Rien ne l'impose :
                 *
                 *   • le « verrou » de la grille web n'est qu'une mention
                 *     textuelle — « · congé validé » — le champ reste modifiable ;
                 *   • `RecordAttendance`, porte commune du web et du terrain,
                 *     n'examine aucun congé et accepte « absent » sans réserve ;
                 *   • la grille pré-remplit d'après `EmployeeLeave::approved()`
                 *     = approuvé/en cours, quand la paie compte AUSSI les congés
                 *     « terminé » : un pointage saisi après coup propose donc
                 *     « présent » sur des jours que la paie décompte en congé.
                 *
                 * Mesuré : 5 jours de congé SANS SOLDE également pointés absents
                 * donnaient 10 jours d'absence, 16 jours travaillés au lieu de
                 * 21, et une retenue de 769 231 GNF au lieu de 384 615 sur un
                 * salaire de 2 000 000. Le bulletin remis au salarié annonçait
                 * « Absence non payée (10j) » pour un congé de cinq jours.
                 *
                 * C'est de l'argent retiré à quelqu'un. La paie ne peut pas
                 * dépendre d'une valeur par défaut d'écran : elle écarte
                 * elle-même les jours qu'elle a déjà comptés.
                 *
                 * Les jours NON pointés restent présumés travaillés (bénéfice du
                 * doute), pour ne pas pénaliser un pointage incomplet.
                 */
                $pointedAbsent = EmployeeAttendance::where('employee_id', $emp->id)
                    ->whereDate('attendance_date', '>=', $contract['start']->toDateString())
                    ->whereDate('attendance_date', '<=', $contract['end']->toDateString())
                    ->where('status', 'absent')
                    ->pluck('attendance_date')
                    ->reject(fn ($jour) => isset($joursDeConge[Carbon::parse($jour)->toDateString()]))
                    // Et pas davantage le JOUR DE REPOS : une absence pointée un
                    // dimanche était retenue comme une journée de travail perdue.
                    // Même raison que pour les congés — la retenue s'exprime en
                    // jours ouvrés, elle ne peut pas en compter d'autres.
                    ->reject(fn ($jour) => self::isRestDay(Carbon::parse($jour)))
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

        // POINTAGE DE LA PÉRIODE — à dire, parce que la paie s'en passe.
        //
        // Les jours non pointés sont présumés travaillés (bénéfice du doute, pour
        // ne pas sanctionner un pointage incomplet). Conséquence : une période SANS
        // AUCUN pointage produit exactement la même paie qu'une période où tout le
        // monde était là tous les jours. Le rapport de présence affiche alors des
        // zéros, la paie affiche des déductions, et rien ne relie les deux.
        //
        // On compte donc les jours pointés, pour que le bureau sache sur quoi la
        // paie repose. Ce n'est pas un blocage : c'est un fait à connaître avant
        // de valider.
        $pointedDays = $this->pointedDaysIn($period);

        return [
            'created'         => $created,
            'skipped'         => $skipped,
            'out_of_contract' => $outOfContract,
            'pointed_days'    => $pointedDays,
        ];
    }

    /**
     * Compte les jours de repos hebdomadaire dans une période (cf. rh.rest_day).
     */
    private function countWeekends(Carbon $start, Carbon $end): int
    {
        $count = 0;
        $current = $start->copy();

        while ($current->lte($end)) {
            if (self::isRestDay($current)) $count++;
            $current->addDay();
        }
        return $count;
    }

    /**
     * Ce jour est-il le repos hebdomadaire de l'exploitation (rh.rest_day) ?
     *
     * Déclaration UNIQUE : le contrôle de pointage doit se taire le jour de
     * repos, faute de quoi il crierait chaque semaine et cesserait d'être lu. En
     * recopier la règle l'aurait fait diverger du calcul de paie — le défaut que
     * cette base a payé une dizaine de fois.
     */
    public static function isRestDay(Carbon $date): bool
    {
        return match (setting('rh.rest_day', 'dimanche')) {
            'dimanche' => $date->isSunday(),
            'samedi'   => $date->isSaturday(),
            'vendredi' => $date->isFriday(),
            'aucun'    => false,
            default    => $date->isSunday(),
        };
    }

    /**
     * Jours POINTÉS d'une période — sur quoi la paie repose réellement.
     *
     * Les jours non pointés sont présumés travaillés (bénéfice du doute). Une
     * période vide produit donc la même paie qu'un mois complet de présence :
     * c'est défendable, mais cela doit être SU avant de valider.
     */
    public function pointedDaysIn(PayrollPeriod $period): int
    {
        return EmployeeAttendance::whereDate('attendance_date', '>=', $period->start_date->toDateString())
            ->whereDate('attendance_date', '<=', $period->end_date->toDateString())
            ->count();
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
