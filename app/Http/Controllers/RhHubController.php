<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Illuminate\Support\Facades\Gate;

/**
 * RhHubController — HUB du module RH (Ressources Humaines).
 *
 * Effectif, présence du jour, masse salariale, accès équipe / paie / congés /
 * pointage / tâches. Cloisonné derrière `rh.L` : ces données du personnel
 * (salaires inclus) ne sont PLUS accessibles via le simple droit Annuaire
 * (tiers). Principe de moindre privilège.
 */
class RhHubController extends Controller
{
    public function index()
    {
        if (Gate::denies('rh.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module RH.');
        }

        $today = now()->toDateString();

        $todayAttendance = EmployeeAttendance::whereDate('attendance_date', $today)->get();

        /*
         * L'EFFECTIF EST CELUI QUE L'EXPLOITATION PAIE — congés compris.
         *
         * Ces deux vignettes comptaient `status = 'Actif'`. Or approuver un congé
         * bascule le statut RH de l'agent en « Congé »
         * (`PayrollController::applyLeaveApproval`) : approuver un congé le
         * faisait donc DISPARAÎTRE de l'effectif et de la masse salariale.
         *
         * Mesuré : sur deux agents à 2 600 000 et 1 400 000 GNF, approuver un
         * congé pour le premier faisait tomber l'effectif de 2 à 1 et la masse
         * salariale de 4 000 000 à 1 400 000 — un décaissement que la paie allait
         * pourtant bien faire. Et la fenêtre ne se referme pas seule : rien ne
         * remet le statut à « Actif », `endLeave` est un bouton qu'il faut
         * cliquer.
         *
         * C'est le défaut déjà corrigé dans la génération de paie, où la règle
         * « qui est au personnel que l'on paie » a été posée une fois. On la lit.
         *
         * La FORMULE, elle, ne change pas : cette masse salariale somme les
         * salaires CONTRACTUELS — « ce que l'exploitation s'engage à verser chaque
         * mois » — quand la période de paie somme les nets réellement calculés.
         * Deux questions différentes, toutes deux légitimes.
         */
        $kpis = [
            'headcount' => (int) Employee::onPayroll()->count(),
            'present'   => (int) $todayAttendance->whereIn('status', EmployeeAttendance::WORKED)->count(),
            'payroll'   => (float) Employee::onPayroll()->sum('salary'),
        ];

        // Répartition de la présence du jour par statut.
        $presence = [];
        foreach (EmployeeAttendance::STATUSES as $key => $label) {
            $presence[$label] = (int) $todayAttendance->where('status', $key)->count();
        }

        return view('rh.index', compact('kpis', 'presence'));
    }
}
