<?php

namespace App\Http\Controllers;

use App\Models\MillMachine;
use App\Models\MaintenanceLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MillMachineController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $machines = MillMachine::withCount('maintenanceLogs')->get();
        return view('provenderie.machines.index', compact('machines'));
    }

    /**
     * Enregistrement d'un nouvel actif.
     */
    public function store(Request $request): RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'                       => 'required|string|max:191|unique:mill_machines,name',
            'type'                       => 'required|string|max:191',
            'capacity_per_hour'          => 'required|numeric|min:0.1',
            'maintenance_interval_hours' => 'required|integer|min:1',
        ]);

        // Nettoyage des chaînes
        $validated['name'] = trim($validated['name']);
        $validated['type'] = trim($validated['type']);

        MillMachine::create($validated + [
            'status'           => 'Opérationnel',
            'total_hours_run'  => 0,
            'last_maintenance' => now()->toDateString(),
        ]);

        return back()->with('success', "Machine {$request->name} intégrée.");
    }

    public function update(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Modification non autorisée.');

        $machine = MillMachine::findOrFail($id);

        $validated = $request->validate([
            'name'                       => 'required|string|max:191|unique:mill_machines,name,' . $id,
            'type'                       => 'required|string|max:191',
            'capacity_per_hour'          => 'required|numeric|min:0.1',
            'maintenance_interval_hours' => 'required|integer|min:1',
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['type'] = trim($validated['type']);

        $machine->update($validated);

        return back()->with('success', "Configuration de {$machine->name} mise à jour.");
    }

    /**
     * Reset de maintenance avec archivage.
     *
     * P-04 corrigé : une seule méthode (l'ancienne resetMaintenance() est supprimée).
     * Crée TOUJOURS un MaintenanceLog pour la traçabilité.
     */
    public function reset(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Privilèges insuffisants.');

        $machine = MillMachine::findOrFail($id);

        $request->validate([
            'description' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($machine, $request) {
            // Archivage avant reset
            MaintenanceLog::create([
                'mill_machine_id'      => $machine->id,
                'user_id'              => Auth::id(),
                'hours_at_maintenance' => $machine->total_hours_run,
                'description'          => $request->description ?? 'Révision standard / Reset compteur',
            ]);

            $machine->update([
                'total_hours_run'  => 0,
                'status'           => 'Opérationnel',
                'last_maintenance' => now()->toDateString(),
            ]);

            return back()->with('success', "Cycle de vie de {$machine->name} réinitialisé. Maintenance archivée.");
        });
    }

    /**
     * Changement de statut (multi-valeurs).
     */
    public function updateStatus(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Modification non autorisée.');

        $machine = MillMachine::findOrFail($id);

        $request->validate([
            'status' => 'required|in:Opérationnel,Maintenance,En Panne,Désactivé',
        ]);

        $machine->update(['status' => $request->status]);

        return back()->with('success', "Statut de {$machine->name} : {$request->status}.");
    }

    /**
     * Toggle rapide Opérationnel ↔ En Panne.
     */
    public function toggleStatus(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Modification non autorisée.');

        $machine = MillMachine::findOrFail($id);

        $newStatus = $request->status ?? (($machine->status === 'En Panne') ? 'Opérationnel' : 'En Panne');
        $machine->update(['status' => $newStatus]);

        $msgType = in_array($newStatus, ['En Panne', 'Désactivé']) ? 'error' : 'success';
        return back()->with($msgType, "{$machine->name} : {$newStatus}.");
    }

    /**
     * Suppression sécurisée.
     * P-11 corrigé : vérifie l'historique de production via la relation.
     */
    public function destroy($id): RedirectResponse
    {
        if (Gate::denies('provenderie.S')) return back()->with('error', 'Suppression réservée à l\'administrateur.');

        $machine = MillMachine::findOrFail($id);

        // P-11 : la relation pivotProductions() n'existe pas sur le modèle ;
        // on délègue au helper hasProductionHistory() qui couvre la machine
        // principale (productions) ET les lignes multi-machines (millProductions).
        if ($machine->hasProductionHistory()) {
            return back()->with('error', "Impossible : {$machine->name} a un historique de production. Utilisez le statut 'Désactivé'.");
        }

        $machine->delete();
        return back()->with('success', 'Machine retirée.');
    }
}
