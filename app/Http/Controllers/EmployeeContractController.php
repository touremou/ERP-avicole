<?php

namespace App\Http\Controllers;

use App\Actions\Hr\DecideContract;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            // Contrats à terme SANS terme : les employés déjà en base avant
            // l'introduction de la colonne. Sans date, ils n'entrent dans aucune
            // fenêtre d'échéance — donc invisibles, donc jamais décidés. Ils
            // passent en tête de l'écran tant qu'il en reste.
            'missingTerm' => Employee::missingContractTerm()->get(),
            'canDecide' => Gate::allows('rh.M'),
        ]);
    }

    /**
     * Régularisation en lot : déclare le terme des contrats qui n'en portaient
     * pas. Une seule soumission pour toute la liste — la saisie se fait avec les
     * techniciens, contrat en main, et ouvrir une fiche par employé
     * transformerait une réunion de dix minutes en corvée.
     *
     * Tout-ou-rien : si une ligne est refusée par la règle métier, aucune n'est
     * enregistrée. Un historique à moitié régularisé serait pire que pas de
     * régularisation du tout — on ne saurait plus ce qui reste à faire.
     */
    public function backfill(Request $request, DecideContract $decide)
    {
        if (Gate::denies('rh.M')) {
            return back()->with('error', __("Seul un gestionnaire RH peut déclarer un terme de contrat."));
        }

        $data = $request->validate([
            'terms'   => 'required|array',
            'terms.*' => 'nullable|date',
            'reason'  => 'nullable|string|max:500',
        ]);

        // Les lignes laissées vides sont ignorées : on régularise ce qu'on sait,
        // on revient plus tard pour le reste.
        $terms = array_filter($data['terms'], fn ($date) => filled($date));

        if ($terms === []) {
            return back()->with('error', __("Aucune date saisie : renseignez au moins un terme."));
        }

        $employees = Employee::missingContractTerm()->whereIn('id', array_keys($terms))->get()->keyBy('id');

        try {
            DB::transaction(function () use ($employees, $terms, $data, $decide) {
                foreach ($terms as $employeeId => $endDate) {
                    $employee = $employees->get((int) $employeeId);
                    if (! $employee) {
                        continue; // déjà régularisé entre-temps (double soumission)
                    }

                    $decide->declareTerm($employee, $endDate, $data['reason'] ?? null, Auth::id());
                }
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', trans_choice(
            '{1} :count contrat régularisé.|[2,*] :count contrats régularisés.',
            count($terms),
            ['count' => count($terms)]
        ));
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
