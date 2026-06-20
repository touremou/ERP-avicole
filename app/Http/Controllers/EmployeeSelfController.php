<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * EmployeeSelfController — « Mon Espace » : page personnelle de l'utilisateur
 * connecté (lecture seule). Si le compte est rattaché à une fiche RH, on
 * affiche ses informations, ses lots sous responsabilité et ses dernières
 * fiches de paie. Sinon, on présente simplement les infos du compte.
 *
 * Aucune action sensible ici : les boutons d'action ne s'affichent que selon
 * les permissions (gates) déjà en place dans les modules concernés.
 */
class EmployeeSelfController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $employee = $user->employee; // peut être null (ex: admin pur)

        $batches = collect();
        $payslips = collect();

        if ($employee) {
            // Lots actifs dont l'employé est responsable.
            $batches = Batch::withoutFarm()
                ->live()
                ->where('employee_id', $employee->id)
                ->where('status', 'Actif')
                ->latest('arrival_date')
                ->get();

            // Dernières fiches de paie (les siennes uniquement).
            if (class_exists(Payslip::class) && Schema::hasTable('payslips')) {
                $payslips = Payslip::where('employee_id', $employee->id)
                    ->with('period')
                    ->latest()
                    ->limit(5)
                    ->get();
            }
        }

        return view('mon-espace.index', compact('user', 'employee', 'batches', 'payslips'));
    }
}
