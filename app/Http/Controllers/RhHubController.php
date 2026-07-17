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

        $kpis = [
            'headcount' => (int) Employee::where('status', 'Actif')->count(),
            'present'   => (int) $todayAttendance->whereIn('status', EmployeeAttendance::WORKED)->count(),
            'payroll'   => (float) Employee::where('status', 'Actif')->sum('salary'),
        ];

        // Répartition de la présence du jour par statut.
        $presence = [];
        foreach (EmployeeAttendance::STATUSES as $key => $label) {
            $presence[$label] = (int) $todayAttendance->where('status', $key)->count();
        }

        return view('rh.index', compact('kpis', 'presence'));
    }
}
