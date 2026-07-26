<?php

namespace App\Http\Controllers;

use App\Actions\Hr\DecideContract;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SUIVI DES CONTRATS À TERME — l'écran qui force la décision.
 *
 * Une date de fin qui ne se lit nulle part ne sert à rien : c'est ce qui rendait
 * l'oubli inévitable. Cette liste range les contrats par URGENCE DE DÉCISION
 * (terme dépassé sans acte d'abord, puis échéance proche), et n'offre que les
 * deux actions réelles : prolonger, ou émettre le préavis.
 *
 * Préfixe de route `employees.` → module RH (Module::routePrefixMap), donc le
 * gate générique se résout correctement. Lecture en rh.L, décisions en rh.M :
 * décider de l'avenir d'un contrat est une modification, pas une consultation.
 */
class EmployeeContractController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('rh.L')) {
            return redirect()->route('dashboard')->with('error', __('Accès restreint.'));
        }

        $days = (int) $request->integer('days', (int) setting('rh.contract_notice_days', 30));
        $days = max(1, min($days, 365));

        $toDecide = Employee::contractsToDecide($days)->with('contractEvents.user')->get();

        // Décidés (préavis émis) et encore à l'effectif : ils restent pointés et
        // payés jusqu'au dernier jour — les cacher ferait oublier la sortie.
        $noticed = Employee::active()
            ->with('contractEvents')
            ->whereNotNull('notice_given_at')
            ->orderBy('contract_end_date')
            ->get();

        return view('employees.contracts', [
            'days'     => $days,
            'toDecide' => $toDecide->sortBy(fn ($e) => $e->days_until_contract_end)->values(),
            'noticed'  => $noticed,
            'canDecide' => Gate::allows('rh.M'),
        ]);
    }

    public function prolong(Request $request, Employee $employee, DecideContract $decide)
    {
        if (Gate::denies('rh.M')) {
            return back()->with('error', __("Seul un gestionnaire RH peut prolonger un contrat."));
        }

        $data = $request->validate([
            'new_end_date' => 'required|date|after:today',
            'reason'       => 'nullable|string|max:500',
        ]);

        try {
            $decide->prolong($employee, $data['new_end_date'], $data['reason'] ?? null, Auth::id());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', __('Contrat de :name prolongé jusqu\'au :date.', [
            'name' => $employee->name,
            'date' => $employee->fresh()->contract_end_date->format('d/m/Y'),
        ]));
    }

    public function notice(Request $request, Employee $employee, DecideContract $decide)
    {
        if (Gate::denies('rh.M')) {
            return back()->with('error', __("Seul un gestionnaire RH peut émettre un préavis."));
        }

        $data = $request->validate([
            'last_day' => 'nullable|date',
            'reason'   => 'nullable|string|max:500',
        ]);

        try {
            $decide->issueNotice($employee, $data['last_day'] ?? null, $data['reason'] ?? null, Auth::id());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', __('Préavis émis pour :name — dernier jour le :date.', [
            'name' => $employee->name,
            'date' => $employee->fresh()->contract_end_date?->format('d/m/Y') ?? '—',
        ]));
    }
}
