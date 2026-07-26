<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\TechnicianWeekService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Fiche de suivi HEBDOMADAIRE par technicien — le support du rituel du lundi.
 *
 * Deux usages, un seul calcul (TechnicianWeekService) :
 *  - le technicien s'auto-suit (sa propre fiche, sans droit RH) ;
 *  - le promoteur compare ses techniciens et n'ouvre que les écarts (rh.L).
 *
 * Le comparatif est délibérément limité aux indicateurs : comparer trois
 * personnes d'un coup d'œil, sans avoir à ouvrir trois fiches.
 */
class TechnicianWeekController extends Controller
{
    public function index(Request $request, TechnicianWeekService $service)
    {
        $week = $this->resolveWeek($request->input('week'));

        // Un technicien consulte SA fiche sans droit RH : c'est sa propre
        // performance, et l'auto-suivi est le premier niveau du dispositif.
        $mine = Auth::user()?->employee;
        $canSeeAll = Gate::allows('rh.L');

        if (! $canSeeAll && ! $mine) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $employees = $canSeeAll
            ? Employee::active()->orderBy('first_name')->get()
            : collect([$mine]);

        $selected = $this->resolveEmployee($request->input('employee_id'), $employees, $mine, $canSeeAll);

        return view('rh.semaine', [
            'week'       => $week,
            'employees'  => $employees,
            'selected'   => $selected,
            'sheet'      => $selected ? $service->forEmployee($selected, $week) : null,
            // Comparatif réservé à qui a la lecture RH : un technicien n'a pas à
            // voir les indicateurs de ses collègues.
            'comparison' => $canSeeAll ? $service->comparison($week) : [],
            'canSeeAll'  => $canSeeAll,
        ]);
    }

    /** Export PDF de la fiche — support du point à distance, hors connexion. */
    public function pdf(Request $request, TechnicianWeekService $service)
    {
        if (Gate::denies('rh.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $week = $this->resolveWeek($request->input('week'));
        $employee = Employee::findOrFail((int) $request->input('employee_id'));
        $sheet = $service->forEmployee($employee, $week);

        $name = str($employee->first_name . '-' . $employee->last_name)->slug();

        return \Pdf::loadView('rh.pdf.semaine', ['sheet' => $sheet])
            ->setPaper('a4', 'portrait')
            ->download("suivi-{$name}-S{$week->isoWeek()}-{$week->year}.pdf");
    }

    /** Semaine demandée (format « 2026-W31 » ou date libre), sinon la semaine courante. */
    private function resolveWeek(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfWeek();
        }

        try {
            // Le champ <input type="week"> renvoie « 2026-W31 ».
            if (preg_match('/^(\d{4})-W(\d{1,2})$/', $value, $m)) {
                return Carbon::now()->setISODate((int) $m[1], (int) $m[2])->startOfWeek();
            }

            return Carbon::parse($value)->startOfWeek();
        } catch (\Throwable) {
            return now()->startOfWeek();
        }
    }

    /**
     * Technicien affiché : celui demandé s'il est autorisé, sinon soi-même,
     * sinon le premier de la liste.
     */
    private function resolveEmployee(?string $requested, $employees, ?Employee $mine, bool $canSeeAll): ?Employee
    {
        if ($requested) {
            $found = $employees->firstWhere('id', (int) $requested);
            // Sans droit RH, on ne consulte que SA fiche — un id d'un collègue
            // dans l'URL ne doit pas ouvrir sa performance.
            if ($found && ($canSeeAll || $found->id === $mine?->id)) {
                return $found;
            }
        }

        return $mine ?? $employees->first();
    }
}
