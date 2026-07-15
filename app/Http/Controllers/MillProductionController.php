<?php

namespace App\Http\Controllers;

use App\Models\Formula;
use App\Models\MillMachine;
use App\Models\MillProduction;
use App\Models\Employee;
use App\Actions\MillProduction\CompleteMillProduction;
use App\Http\Requests\MillProduction\StoreMillProductionRequest;
use Illuminate\Http\RedirectResponse;
use App\Actions\Provenderie\RecordProductionConsumptionAction;
use App\Actions\Provenderie\NormalizeFormulaNameAction;
use App\Services\UnitConverter;
use App\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MillProductionController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $productions = MillProduction::with(['formula', 'machine', 'machines', 'supervisor', 'user'])
            ->latest()
            ->paginate((int) setting('general.items_per_page', 20));

        return view('provenderie.production.index', compact('productions'));
    }

    public function create(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Privilèges insuffisants.');

        // Eager-load items + rawMaterial pour éviter le N+1 et garantir que
        // les matières premières sont disponibles dans la vue sans requête
        // supplémentaire (une MP supprimée vaudra null, géré dans la vue).
        $formulas = Formula::where('is_active', true)
            ->with('items.rawMaterial')
            ->orderBy('name')
            ->get();
        $machines = MillMachine::where('status', 'Opérationnel')->get();
        $employees = Employee::where('status', 'Actif')->orderBy('last_name')->get();

        return view('provenderie.production.create', compact('formulas', 'machines', 'employees'));
    }

    /**
     * Planification d'un Ordre de Production.
     */
    /*
    public function store(StoreMillProductionRequest $request): RedirectResponse
    {
        $totalWeight = UnitConverter::sacksToKg((float) $request->nb_bags);

        $production = DB::transaction(function () use ($request, $totalWeight) {
            $prod = MillProduction::create([
                'batch_number'      => DocumentNumberingService::generate('mill_production'),
                'formula_id'        => $request->formula_id,
                'machine_id'        => $request->machine_ids[0],
                'quantity_produced' => $totalWeight,
                'supervisor_id'     => $request->supervisor_id,
                'operator_id'       => auth()->id(),
                'status'            => 'Planifié',
            ]);

            $prod->machines()->attach($request->machine_ids);

            return $prod;
        });

        return redirect()->route('production.index')
            ->with('success', "OP {$production->batch_number} : {$totalWeight} kg planifiés.");
    }
    */
    public function store(StoreMillProductionRequest $request): RedirectResponse
    {
        // Occupation machine : une machine ne traite qu'un OP à la fois. Un OP
        // est « ouvert » tant qu'il n'est ni clôturé (Terminé) ni annulé — dans
        // ce système il naît « Planifié » et passe directement à « Terminé » à
        // la clôture (pas d'état « En cours » intermédiaire persisté). On bloque
        // donc sur tout OP ouvert occupant l'une des machines demandées.
        $busyIds = DB::table('mill_production_machine')
            ->join('mill_productions', 'mill_productions.id', '=', 'mill_production_machine.mill_production_id')
            ->whereIn('mill_production_machine.mill_machine_id', $request->machine_ids)
            ->whereNotIn('mill_productions.status', ['Terminé', 'Annulé'])
            ->pluck('mill_production_machine.mill_machine_id')
            ->unique();

        if ($busyIds->isNotEmpty()) {
            $names = MillMachine::whereIn('id', $busyIds)->pluck('name')->join(', ');
            return back()->withInput()->with('error', "Machine(s) déjà engagée(s) sur un ordre en cours : {$names}. Clôturez l'OP ouvert avant d'en planifier un nouveau sur cette machine.");
        }

        $totalWeight = UnitConverter::sacksToKg((float) $request->nb_bags);

        $production = DB::transaction(function () use ($request, $totalWeight) {
            $prod = MillProduction::create([
                'batch_number'      => DocumentNumberingService::generate('mill_production'),
                'formula_id'        => $request->formula_id,
                // ON A SUPPRIMÉ 'machine_id' ICI
                'quantity_produced' => $totalWeight,
                'supervisor_id'     => $request->supervisor_id,
                'operator_id'       => auth()->id(),
                'status'            => 'Planifié',
            ]);

            // On attache toutes les machines uniquement via la table pivot
            // Remplacer : $prod->machines()->attach($request->machine_ids);
            // Par ceci :
            $machinesData = [];
            foreach ($request->machine_ids as $mId) {
                $machine = MillMachine::find($mId);
                $machinesData[$mId] = [
                    'snapshot_capacity_per_hour' => $machine->capacity_per_hour
                ];
            }
            $prod->machines()->attach($machinesData);

            return $prod;
        });

        return redirect()->route('production.index')
            ->with('success', "OP {$production->batch_number} planifiée.");
    }

    /**
     * Clôture industrielle.
     * P-01, P-02, P-06 corrigés : tout est dans CompleteMillProduction Action.
     */
    public function complete($id, CompleteMillProduction $action): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Seul un responsable peut clôturer.');

        $production = MillProduction::with(['formula.items.rawMaterial', 'machine', 'machines'])->findOrFail($id);

        try {
            $result = $action->execute($production);

            return redirect()->route('production.index')
                ->with('success', "OP #{$result->batch_number} clôturée. Coût réel : {$result->real_cost_per_kg} GNF/kg.");
        } catch (\Exception $e) {
            Log::error("Échec clôture OP #{$id} : {$e->getMessage()}");
            return back()->with('error', $e->getMessage());
        }
    }

    public function show($id): View
    {
        $production = MillProduction::with(['formula.items.rawMaterial', 'machine', 'user'])->findOrFail($id);
        return view('provenderie.production.show', compact('production'));
    }

    /**
     * Annule un ordre de production NON clôturé, libérant la/les machine(s)
     * engagée(s). Indispensable : sans annulation, un OP planifié jamais
     * clôturé (panne, erreur de saisie) bloquerait sa machine indéfiniment
     * (l'occupation ne se libère que sur « Terminé » ou « Annulé »).
     *
     * Sûr : la consommation des matières premières n'a lieu qu'à la clôture,
     * donc aucun stock à contre-passer pour un OP planifié.
     */
    public function cancel($id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Seul un responsable peut annuler un ordre.');

        $production = MillProduction::findOrFail($id);

        if (in_array($production->status, ['Terminé', 'Annulé'], true)) {
            return back()->with('error', "Cet ordre est déjà {$production->status} — annulation impossible.");
        }

        $production->update(['status' => 'Annulé']);

        return redirect()->route('production.index')
            ->with('success', "OP #{$production->batch_number} annulée. Machine(s) libérée(s).");
    }
}
