<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Services\NotificationHub;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PayrollController extends Controller
{
    // ─── PAIE ───

    public function index()
    {
        if (Gate::denies('annuaire.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $periods = PayrollPeriod::withCount('payslips')
            ->orderByDesc('year')->orderByDesc('month')
            ->paginate((int) setting('general.items_per_page', 20));

        $currentMonth = now()->format('Y-m');
        $hasCurrent = PayrollPeriod::where('year', now()->year)->where('month', now()->month)->exists();

        return view('payroll.index', compact('periods', 'currentMonth', 'hasCurrent'));
    }

    public function createPeriod(Request $request)
    {
        if (Gate::denies('annuaire.C')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'year'  => 'required|integer|min:2024|max:2030',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $start = Carbon::create($validated['year'], $validated['month'], 1);
        $end = $start->copy()->endOfMonth();

        $period = PayrollPeriod::firstOrCreate(
            ['year' => $validated['year'], 'month' => $validated['month']],
            [
                'label'      => $start->translatedFormat('F Y'),
                'start_date' => $start,
                'end_date'   => $end,
                'status'     => 'brouillon',
            ]
        );

        return redirect()->route('payroll.show', $period)
            ->with('success', "Période {$period->label} créée.");
    }

    public function show(PayrollPeriod $period)
    {
        if (Gate::denies('annuaire.L')) return back()->with('error', 'Accès restreint.');

        $period->load(['payslips.employee', 'payslips.lines']);

        $kpi = [
            'total_employees' => $period->payslips->count(),
            'total_brut'      => $period->payslips->sum('base_salary'),
            'total_primes'    => $period->payslips->sum('total_primes'),
            'total_deductions' => $period->payslips->sum('total_deductions'),
            'total_net'       => $period->payslips->sum('net_salary'),
            'paid_count'      => $period->payslips->where('payment_status', 'paye')->count(),
            'pending_count'   => $period->payslips->where('payment_status', 'en_attente')->count(),
        ];

        return view('payroll.show', compact('period', 'kpi'));
    }

    public function generate(PayrollPeriod $period, PayrollService $service)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Non autorisé.');

        if ($period->status === 'paye') {
            return back()->with('error', 'Cette période est déjà payée et verrouillée.');
        }

        $result = $service->generatePayroll($period);

        return back()->with('success', "{$result['created']} fiches générées, {$result['skipped']} déjà existantes.");
    }

    public function addLine(Request $request, Payslip $payslip)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Non autorisé.');

        if ($payslip->isLocked()) {
            return back()->with('error', 'Bulletin déjà payé : aucune prime ni déduction ne peut être ajoutée.');
        }

        $validated = $request->validate([
            'type'     => 'required|in:prime,deduction',
            'label'    => 'required|string|max:255',
            'amount'   => 'required|integer|min:1',
            'category' => 'nullable|string|max:50',
        ]);

        PayslipLine::create(array_merge($validated, ['payslip_id' => $payslip->id]));
        $payslip->recalculate();

        $label = $validated['type'] === 'prime' ? 'Prime' : 'Déduction';
        return back()->with('success', "{$label} \"{$validated['label']}\" ajoutée : " . number_format($validated['amount']) . " GNF.");
    }

    /**
     * Enregistre des heures supplémentaires : crée/maj une prime calculée au
     * taux horaire majoré (paramètre rh.overtime_rate). Base mensuelle de
     * référence : 26 jours × 8 h = 208 h.
     */
    public function recordOvertime(Request $request, Payslip $payslip)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Non autorisé.');

        if ($payslip->isLocked()) {
            return back()->with('error', 'Bulletin déjà payé : impossible d\'enregistrer des heures supplémentaires.');
        }

        $validated = $request->validate([
            'hours' => 'required|numeric|min:0.5|max:300',
        ]);

        // Taux horaire = salaire mensuel / durée mensuelle contractuelle. La base
        // (208 h = 26 j × 8 h) est paramétrable selon la convention applicable ;
        // garde-fou contre une valeur nulle/absente (division par zéro).
        $monthlyHours = max(1, (float) setting('rh.monthly_hours', 208));
        $rate         = (float) setting('rh.overtime_rate', 1.5);
        $hourlyRate   = (float) $payslip->base_salary / $monthlyHours;
        $amount       = (int) round($hourlyRate * $validated['hours'] * $rate);

        PayslipLine::updateOrCreate(
            ['payslip_id' => $payslip->id, 'category' => 'heures_sup'],
            [
                'type'   => 'prime',
                'label'  => "Heures sup. ({$validated['hours']} h × {$rate})",
                'amount' => $amount,
            ]
        );

        $payslip->update(['overtime_hours' => $validated['hours']]);
        $payslip->recalculate();

        return back()->with('success', "Heures sup. enregistrées : {$validated['hours']} h → +" . number_format($amount) . ' GNF.');
    }

    public function removeLine(PayslipLine $line)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Non autorisé.');

        $payslip = $line->payslip;

        if ($payslip->isLocked()) {
            return back()->with('error', 'Bulletin déjà payé : ses lignes ne peuvent plus être supprimées.');
        }

        $line->delete();
        $payslip->recalculate();

        return back()->with('success', 'Ligne supprimée.');
    }

    public function markPaid(Request $request, Payslip $payslip)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'payment_method'    => 'required|in:especes,orange_money,virement',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        $payslip->update([
            'payment_method'    => $validated['payment_method'],
            'payment_reference' => $validated['payment_reference'] ?? null,
            'payment_status'    => 'paye',
            'paid_at'           => now(),
        ]);

        return back()->with('success', "Paiement enregistré pour {$payslip->employee->first_name} {$payslip->employee->last_name}.");
    }

    public function validatePeriod(PayrollPeriod $period)
    {
        if (Gate::denies('annuaire.S')) return back()->with('error', 'Validation réservée aux administrateurs.');

        $period->update([
            'status'       => 'valide',
            'validated_by' => Auth::id(),
            'validated_at' => now(),
        ]);

        return back()->with('success', "Période {$period->label} validée.");
    }

    // ─── CONGÉS ───

    public function leaves()
    {
        if (Gate::denies('annuaire.L')) return back()->with('error', 'Accès restreint.');

        $leaves = EmployeeLeave::with('employee')
            ->orderByDesc('start_date')
            ->paginate((int) setting('general.items_per_page', 20));

        $employees = Employee::where('status', 'Actif')->orderBy('first_name')->get();

        // KPI congés
        $kpi = [
            'pending'   => EmployeeLeave::where('status', 'demande')->count(),
            'on_leave'  => EmployeeLeave::where('status', 'en_cours')->count(),
            'this_month' => EmployeeLeave::where('start_date', '>=', now()->startOfMonth())->count(),
        ];

        return view('payroll.leaves', compact('leaves', 'employees', 'kpi'));
    }

    public function storeLeave(Request $request)
    {
        if (Gate::denies('annuaire.C')) return back()->with('error', 'Non autorisé.');

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'type'        => 'required|in:conge_annuel,maladie,maternite,sans_solde,absence,formation,autre',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'reason'      => 'nullable|string|max:500',
        ]);

        $days = Carbon::parse($validated['start_date'])->diffInDays(Carbon::parse($validated['end_date'])) + 1;

        // Habilité (RH / Manager / Admin = droit annuaire.S) : la saisie vaut
        // approbation immédiate. Sinon, c'est une simple demande à valider.
        $autoApprove = Gate::allows('annuaire.S');

        $leave = EmployeeLeave::create(array_merge($validated, [
            'days_count'   => $days,
            'status'       => $autoApprove ? 'approuve' : 'demande',
            'requested_by' => Auth::id(),
            'approved_by'  => $autoApprove ? Auth::id() : null,
            'approved_at'  => $autoApprove ? now() : null,
        ]));

        if ($autoApprove) {
            $this->applyLeaveApproval($leave);
            return back()->with('success', "Congé approuvé : {$days} jours.");
        }

        // Notifier les responsables RH de la nouvelle demande
        rescue(fn() => app(NotificationHub::class)->notifyLeaveRequested($leave));

        return back()->with('success', "Demande de congé enregistrée ({$days} jours) — en attente d'approbation.");
    }

    /**
     * Approuve une demande de congé (réservé aux habilités, droit S).
     */
    public function approveLeave(EmployeeLeave $leave)
    {
        if (Gate::denies('annuaire.S')) return back()->with('error', "Approbation réservée aux responsables RH (droit S).");

        if ($leave->status !== 'demande') {
            return back()->with('error', 'Cette demande a déjà été traitée.');
        }

        $leave->update([
            'status'      => 'approuve',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);
        $this->applyLeaveApproval($leave);

        rescue(fn() => app(NotificationHub::class)->notifyLeaveApproved($leave->fresh()));

        return back()->with('success', "Congé de {$leave->employee->first_name} approuvé.");
    }

    /**
     * Refuse une demande de congé (réservé aux habilités, droit S).
     */
    public function rejectLeave(Request $request, EmployeeLeave $leave)
    {
        if (Gate::denies('annuaire.S')) return back()->with('error', "Refus réservé aux responsables RH (droit S).");

        if ($leave->status !== 'demande') {
            return back()->with('error', 'Cette demande a déjà été traitée.');
        }

        $validated = $request->validate(['rejection_reason' => 'required|string|max:500']);

        $leave->update([
            'status'           => 'refuse',
            'approved_by'      => Auth::id(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        rescue(fn() => app(NotificationHub::class)->notifyLeaveRejected($leave->fresh()));

        return back()->with('success', "Demande de {$leave->employee->first_name} refusée.");
    }

    /**
     * Effets d'un congé approuvé : décompte du solde (congé annuel) et bascule
     * du statut employé en « Congé » si le congé est actif aujourd'hui.
     */
    private function applyLeaveApproval(EmployeeLeave $leave): void
    {
        if ($leave->type === 'conge_annuel'
            && \Illuminate\Support\Facades\Schema::hasColumn('employees', 'annual_leave_balance')) {
            $leave->employee?->decrement('annual_leave_balance', $leave->days_count);
        }

        // Le statut « Congé » ne s'applique que si l'absence couvre aujourd'hui.
        if ($leave->isActiveOn(now())) {
            $leave->employee?->update(['status' => 'Congé']);
        }
    }

    /**
     * Délègue les tâches en attente d'un employé absent (sur la fenêtre du
     * congé) vers un collègue disponible. Couvre le cas « un employé en congé
     * doit pouvoir confier ses tâches à un collègue pendant son absence ».
     */
    public function delegateLeaveTasks(Request $request, EmployeeLeave $leave)
    {
        // Un gestionnaire (annuaire.M) OU l'employé absent lui-même peut déléguer.
        $isManager  = Gate::allows('annuaire.M');
        $isOwnLeave = $leave->employee?->user_id === Auth::id();

        if (! $isManager && ! $isOwnLeave) {
            return back()->with('error', 'Non autorisé.');
        }

        $validated = $request->validate([
            'delegate_to' => 'required|exists:employees,id',
        ]);

        $delegate = Employee::findOrFail($validated['delegate_to']);
        if ($delegate->id === $leave->employee_id) {
            return back()->with('error', "Impossible de déléguer à l'employé absent lui-même.");
        }

        $reassigned = \App\Models\TaskAssignment::where('employee_id', $leave->employee_id)
            ->whereIn('status', ['a_faire', 'en_retard'])
            ->whereDate('scheduled_date', '>=', $leave->start_date->toDateString())
            ->whereDate('scheduled_date', '<=', $leave->end_date->toDateString())
            ->update(['employee_id' => $delegate->id]);

        return back()->with('success',
            "{$reassigned} tâche(s) de {$leave->employee->first_name} déléguée(s) à {$delegate->first_name} {$delegate->last_name}."
        );
    }

    public function endLeave(EmployeeLeave $leave)
    {
        if (Gate::denies('annuaire.M')) return back()->with('error', 'Non autorisé.');

        $leave->update(['status' => 'termine']);
        $leave->employee->update(['status' => 'Actif']);

        return back()->with('success', "{$leave->employee->first_name} est de retour.");
    }

    /**
     * Impression d'un bon de paie (avant paiement) ou fiche de paie (après paiement).
     */
    public function printPayslip(Payslip $payslip, Request $request)
    {
        if (Gate::denies('annuaire.L')) return back()->with('error', 'Accès restreint.');

        $payslip->load(['employee', 'period', 'lines']);
        $type = $request->input('type', $payslip->payment_status === 'paye' ? 'fiche' : 'bon');

        return view('payroll.print', compact('payslip', 'type'));
    }

    /**
     * Historique de paie d'un employé (pour la fiche employé).
     */
    public function employeeHistory(Employee $employee)
    {
        if (Gate::denies('annuaire.L')) return back()->with('error', 'Accès restreint.');

        $payslips = Payslip::where('employee_id', $employee->id)
            ->with(['period', 'lines'])
            ->orderByDesc('created_at')
            ->paginate((int) setting('general.items_per_page', 20));

        $leaves = EmployeeLeave::where('employee_id', $employee->id)
            ->orderByDesc('start_date')
            ->get();

        $totals = [
            'total_earned'    => Payslip::where('employee_id', $employee->id)->sum('net_salary'),
            'total_primes'    => Payslip::where('employee_id', $employee->id)->sum('total_primes'),
            'total_deductions' => Payslip::where('employee_id', $employee->id)->sum('total_deductions'),
            'months_paid'     => Payslip::where('employee_id', $employee->id)->where('payment_status', 'paye')->count(),
            'leave_days_used' => EmployeeLeave::where('employee_id', $employee->id)->whereIn('status', ['approuve', 'en_cours', 'termine'])->sum('days_count'),
        ];

        return view('employees.payroll-history', compact('employee', 'payslips', 'leaves', 'totals'));
    }
}
