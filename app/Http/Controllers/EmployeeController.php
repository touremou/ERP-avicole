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
        $farmId = session('current_farm_id');
        $employees = Employee::withoutGlobalScopes()
            ->with('user')
            ->when($farmId, function ($q) use ($farmId) {
                $accessUserIds = \Illuminate\Support\Facades\DB::table('farm_user')
                    ->where('farm_id', $farmId)->pluck('user_id');
                $q->where(function ($sub) use ($farmId, $accessUserIds) {
                    $sub->where('farm_id', $farmId)
                        ->orWhereIn('user_id', $accessUserIds);
                });
            })
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

    public function show($id) 
    {
        if (Gate::denies('rh.L') && Gate::denies('rh.L')) return back()->with('error', 'Accès restreint.');
        // On conserve $id ici car le withTrashed() est requis pour voir les archives
        $employee = Employee::withTrashed()->with('batches')->findOrFail($id);
        return view('employees.show', compact('employee'));
    }

    public function edit(Employee $employee) 
    {
        if (Gate::denies('rh.M')) return back()->with('error', 'Modification de profil interdite.');
        return view('employees.edit', compact('employee'));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployee $updateEmployee) 
    {
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