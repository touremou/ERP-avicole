<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Provider;
use Illuminate\Support\Facades\Gate;

/**
 * AnnuaireHubController — HUB du module Annuaire / RH.
 *
 * Effectif, présence du jour, masse salariale et fournisseurs, + accès groupés
 * (équipe / présence-paie / partenaires). Même pattern que les autres hubs.
 */
class AnnuaireHubController extends Controller
{
    public function index()
    {
        if (Gate::denies('annuaire.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint au module Annuaire.');
        }

        $today = now()->toDateString();

        $todayAttendance = EmployeeAttendance::whereDate('attendance_date', $today)->get();

        $kpis = [
            'headcount'  => (int) Employee::where('status', 'Actif')->count(),
            'present'    => (int) $todayAttendance->whereIn('status', EmployeeAttendance::WORKED)->count(),
            'payroll'    => (float) Employee::where('status', 'Actif')->sum('salary'),
            'providers'  => (int) Provider::active()->count(),
        ];

        // Répartition de la présence du jour par statut.
        $presence = [];
        foreach (EmployeeAttendance::STATUSES as $key => $label) {
            $presence[$label] = (int) $todayAttendance->where('status', $key)->count();
        }

        return view('annuaire.index', compact('kpis', 'presence'));
    }
}
