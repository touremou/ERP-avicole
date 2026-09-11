<?php

namespace App\Models;

use App\Traits\BelongsToFarm;
use App\Traits\ReferencesEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use BelongsToFarm, ReferencesEmployee;

    protected $fillable = [
        'farm_id', 'payroll_period_id', 'employee_id',
        'base_salary', 'total_primes', 'total_deductions', 'net_salary',
        'days_worked', 'days_absent', 'days_leave', 'overtime_hours',
        'payment_method', 'payment_reference', 'payment_status', 'paid_at',
        'notes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function period(): BelongsTo { return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id'); }
    public function lines(): HasMany { return $this->hasMany(PayslipLine::class); }

    public function primes(): HasMany { return $this->lines()->where('type', 'prime'); }
    public function deductions(): HasMany { return $this->lines()->where('type', 'deduction'); }

    /**
     * Bulletin verrouillé : aucune modification de ligne (prime, déduction,
     * heures sup.) n'est permise une fois le bulletin payé, ou la période
     * APPROUVÉE ou soldée. Garde-fou comptable : un bulletin payé est immuable.
     *
     * ─── POURQUOI « VALIDE » EN FAIT PARTIE ───
     *
     * Ce verrou ne connaissait que le PAIEMENT, et les trois écrivains de lignes
     * (`addLine`, `recordOvertime`, `removeLine`) ne consultent que lui : le
     * statut `valide` n'interdisait donc rien. Or `markPaid` dit en toutes
     * lettres ce qu'approuver veut dire — « `validatePeriod` exige le droit
     * `rh.S` (administrateur) : c'est le moment où quelqu'un approuve la paie
     * AVANT QUE L'ARGENT SORTE ».
     *
     * Mesuré : une paie approuvée à 2 600 000 GNF passait à 3 600 000 par une
     * prime ajoutée après coup en `rh.M`, puis réglée — `TreasuryPostingService`
     * lit `net_salary` en direct. L'approbation `rh.S` portait sur un montant
     * que `rh.M` pouvait changer derrière elle.
     *
     * Corriger une paie approuvée reste possible, mais par la porte de devant :
     * `PayrollController::reopenPeriod` (rh.S, le rang de l'approbation) retire
     * la signature et ramène la période en « calculée ».
     */
    public function isLocked(): bool
    {
        return $this->payment_status === 'paye'
            || in_array($this->period?->status, ['valide', 'paye'], true);
    }

    /**
     * Recalcule le net depuis les lignes, ET les totaux de sa période.
     *
     * ─── POURQUOI LA PÉRIODE SUIT ICI ───
     *
     * `payroll_periods.total_net` est la somme des nets des bulletins, et n'était
     * écrit que par `PayrollPeriod::recalculateTotals()` — dont l'unique appelant
     * de toute l'application était la fin de `PayrollService::generatePayroll()`.
     *
     * Or trois gestes modifient un bulletin APRÈS la génération (`addLine`,
     * `recordOvertime`, `removeLine`) et les trois n'appelaient que cette
     * méthode-ci. Le bulletin bougeait, la période non — et les lecteurs ne
     * lisent pas la même chose : la LISTE des périodes affiche la valeur stockée,
     * le DÉTAIL et la comptabilité (`Accounting\PeriodCharges`) recalculent en
     * direct.
     *
     * Mesuré : après une génération à 2 600 000 GNF, une prime de 500 000 laissait
     * la liste à 2 600 000 et le détail à 3 100 000. L'écart était permanent et
     * valait la somme de TOUTES les lignes saisies après la génération.
     *
     * La mise à jour est posée ICI, et non chez les trois appelants : un
     * quatrième oublierait, comme ces trois-là ont oublié. Tout changement de net
     * passe forcément par cette méthode.
     *
     * Coût : pendant la génération, `PayrollService` appelle `recalculate()` par
     * bulletin, ce qui ajoute un agrégat par employé — quelques dizaines de
     * requêtes, dans la transaction d'une opération mensuelle. Le prix d'une
     * période dont les totaux ne peuvent pas mentir.
     */
    public function recalculate(): void
    {
        $primes = (int) $this->lines()->where('type', 'prime')->sum('amount');
        $deductions = (int) $this->lines()->where('type', 'deduction')->sum('amount');

        $this->update([
            'total_primes'     => $primes,
            'total_deductions' => $deductions,
            'net_salary'       => $this->base_salary + $primes - $deductions,
        ]);

        $this->period?->recalculateTotals();
    }
}
