<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Actions\Employee\CreateEmployee;
use App\Actions\Employee\UpdateEmployee;
use App\Actions\Employee\ArchiveEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index() 
    {
        if (Gate::denies('rh.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint au personnel.');

        // Employés RATTACHÉS à la ferme courante (farm_id) OU dont le compte a
        // reçu l'ACCÈS à cette ferme (farm_user). Sans ce second volet, un
        // employé affecté à un autre site pour y travailler obtenait les droits
        // mais n'apparaissait pas dans la liste RH de ce site.
        // Règle de visibilité portée par le MODÈLE (scopeVisibleInCurrentFarm) :
        // la même que celle du binding {employee}. Quand elle vivait ici, les
        // routes à paramètre appliquaient le global scope de ferme et un employé
        // prêté était listé sans être ouvrable (404 sur /employees/4).
        $employees = Employee::visibleInCurrentFarm()
            ->with('user')
            ->orderBy('last_name', 'asc')
            ->get();
        // Rôles proposés pour la création d'accès en masse (outil admin.S).
        $roles = \App\Models\Role::orderBy('display_name')->get(['id', 'display_name', 'label', 'name']);

        return view('employees.index', compact('employees', 'roles'));
    }

    public function create() 
    {
        if (Gate::denies('rh.C')) return back()->with('error', 'Privilèges de recrutement insuffisants.');
        return view('employees.create');
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee) 
    {
        if (Gate::denies('rh.C')) return back()->with('error', 'Privilèges de recrutement insuffisants.');
        $employee = $createEmployee->execute(
            $request->validated(),
            $request->file('photo'),
            $request->file('cv')
        );

        return redirect()->route('employees.index')
            ->with('success', "L'agent {$employee->last_name} a été intégré au système sous le matricule {$employee->employee_id}.");
    }

    public function show(Employee $employee)
    {
        if (Gate::denies('rh.L')) return back()->with('error', 'Accès restreint.');

        // L'employé est résolu par le binding {employee} (AppServiceProvider),
        // qui applique la règle de visibilité UNIQUE et inclut les archives :
        // la fiche d'un sortant reste consultable.
        $employee->load('batches');

        return view('employees.show', compact('employee'));
    }

    public function edit(Employee $employee) 
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Modification de profil interdite.');

        // Une fiche archivée se consulte mais ne se modifie pas : il faut la
        // restaurer d'abord. Dit explicitement — avant, le scope SoftDeletes
        // renvoyait un 404 muet qui laissait chercher une route cassée.
        if ($employee->trashed()) {
            return redirect()->route('employees.show', $employee->id)
                ->with('error', __("Cette fiche est archivée : restaurez-la avant de la modifier."));
        }

        return view('employees.edit', compact('employee'));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployee $updateEmployee) 
    {
        // Pendant serveur de la garde d'`edit` : l'écran peut être contourné.
        if ($employee->trashed()) {
            return redirect()->route('employees.show', $employee->id)
                ->with('error', __("Cette fiche est archivée : restaurez-la avant de la modifier."));
        }

        $updateEmployee->execute(
            $employee,
            $request->validated(),
            $request->file('photo'),
            $request->file('cv')
        );

        return redirect()->route('employees.show', $employee->id)->with('success', 'Modifications enregistrées.');
    }

    public function destroy(Employee $employee, ArchiveEmployee $archiveEmployee) 
    {
        if (Gate::denies('rh.S')) return back()->with('error', 'Seul un administrateur peut archiver un employé.');

        if ($employee->trashed()) {
            return back()->with('error', __("Cette fiche est déjà archivée."));
        }

        try {
            $archiveEmployee->execute($employee);
            return redirect()->route('employees.index')->with('success', "L'employé a été déplacé vers les archives.");
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateStatus(Request $request, Employee $employee) 
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Action non autorisée.');
        
        $request->validate(['status' => 'required|in:Actif,Suspendu,Congé']);
        $employee->update(['status' => $request->status]);

        return back()->with('success', "Statut RH mis à jour : {$request->status}");
    }
}