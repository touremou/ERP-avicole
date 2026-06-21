<?php

namespace App\Http\Controllers;

use App\Actions\Crop\RecordCropInput;
use App\Actions\Crop\RecordHarvest;
use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropSpecies;
use App\Models\Employee;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Cycles de culture (module: cultures) — du semis à la clôture, avec saisie
 * des récoltes.
 */
class CropCycleController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $query = CropCycle::with(['plot:id,name', 'harvests:id,crop_cycle_id,quantity']);

        if ($request->get('filter') === 'archives') {
            $query->archived();
        } else {
            $query->whereNotIn('status', CropCycle::STATUS_ARCHIVED);
        }

        $cycles = $query->orderByDesc('planting_date')
            ->paginate((int) setting('general.items_per_page', 20))
            ->withQueryString();

        return view('cultures.cycles.index', [
            'cycles' => $cycles,
            'filter' => $request->get('filter', 'actifs'),
        ]);
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.cycles.create', [
            'plots'     => Plot::available()->orderBy('name')->get(),
            'employees' => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'campaigns' => CropCampaign::where('status', '!=', CropCampaign::STATUS_CLOTUREE)->orderByDesc('start_date')->get(['id', 'name', 'year']),
            'species'   => CropSpecies::active()->orderBy('name')->get(['id', 'name', 'avg_yield_tha']),
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'plot_id'                => 'required|exists:plots,id',
            'campaign_id'            => 'nullable|exists:crop_campaigns,id',
            'employee_id'            => 'nullable|exists:employees,id',
            'code'                   => 'nullable|string|max:50',
            'crop_name'              => 'required|string|max:255',
            'variety'                => 'nullable|string|max:255',
            'area_used_ha'           => 'required|numeric|min:0',
            'planting_date'          => 'required|date',
            'expected_harvest_date'  => 'nullable|date|after_or_equal:planting_date',
            'seed_quantity'          => 'nullable|numeric|min:0',
            'seed_unit'              => 'nullable|string|max:20',
            'expected_yield_kg'      => 'nullable|numeric|min:0',
            'total_acquisition_cost' => 'nullable|numeric|min:0',
            'additional_costs'       => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:1000',
        ]);

        $validated['total_acquisition_cost'] = $validated['total_acquisition_cost'] ?? 0;
        $validated['additional_costs']       = $validated['additional_costs'] ?? 0;

        $cycle = CropCycle::create($validated);

        // La parcelle passe en culture.
        $cycle->plot()->update(['status' => Plot::STATUS_EN_CULTURE]);

        return redirect()->route('crop-cycles.show', $cycle)
            ->with('success', "Cycle de culture « {$cycle->crop_name} » démarré.");
    }

    public function show(CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $cropCycle->load([
            'plot', 'campaign:id,name', 'employee:id,first_name,last_name',
            'harvests.employee:id,first_name,last_name',
            'inputs.provider:id,name',
        ]);

        return view('cultures.cycles.show', [
            'cycle'      => $cropCycle,
            'qualities'  => Harvest::QUALITIES,
            'inputTypes' => CropInput::TYPES,
            'employees'  => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'providers'  => Provider::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'crop_name'              => 'required|string|max:255',
            'variety'                => 'nullable|string|max:255',
            'employee_id'            => 'nullable|exists:employees,id',
            'expected_harvest_date'  => 'nullable|date',
            'expected_yield_kg'      => 'nullable|numeric|min:0',
            'total_acquisition_cost' => 'nullable|numeric|min:0',
            'additional_costs'       => 'nullable|numeric|min:0',
            'total_revenue'          => 'nullable|numeric|min:0',
            'status'                 => 'required|in:' . implode(',', CropCycle::EDITABLE_STATUSES),
            'notes'                  => 'nullable|string|max:1000',
        ]);

        $cropCycle->update($validated);

        // À la clôture/abandon, on libère la parcelle.
        if (in_array($cropCycle->status, CropCycle::STATUS_ARCHIVED, true)) {
            $cropCycle->update(['closing_date' => $cropCycle->closing_date ?? now()]);
            $cropCycle->plot()->update(['status' => Plot::STATUS_DISPONIBLE]);
        }

        return back()->with('success', 'Cycle de culture mis à jour.');
    }

    /**
     * Saisie d'une récolte sur le cycle (avec intégration stock optionnelle).
     */
    public function storeHarvest(Request $request, CropCycle $cropCycle, RecordHarvest $action)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if ($cropCycle->isArchived()) {
            return back()->with('error', 'Ce cycle est clôturé : aucune récolte ne peut y être ajoutée.');
        }

        $validated = $request->validate([
            'harvest_date'    => 'required|date',
            'quantity'        => 'required|numeric|min:0.001',
            'unit'            => 'nullable|string|max:20',
            'loss_quantity'   => 'nullable|numeric|min:0',
            'quality'         => 'nullable|in:' . implode(',', Harvest::QUALITIES),
            'employee_id'     => 'nullable|exists:employees,id',
            'unit_price'      => 'nullable|numeric|min:0',
            'sync_to_stock'   => 'nullable|boolean',
            'stock_item_name' => 'nullable|string|max:255',
            'notes'           => 'nullable|string|max:500',
        ]);

        $action->execute($cropCycle, $validated);

        return redirect()->route('crop-cycles.show', $cropCycle)->with('success', 'Récolte enregistrée.');
    }

    /**
     * Saisie d'un intrant itémisé sur le cycle (avec intégration stock optionnelle).
     */
    public function storeInput(Request $request, CropCycle $cropCycle, RecordCropInput $action)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'type'            => 'required|in:' . implode(',', array_keys(CropInput::TYPES)),
            'name'            => 'required|string|max:255',
            'quantity'        => 'nullable|numeric|min:0',
            'unit'            => 'nullable|string|max:20',
            'unit_cost'       => 'nullable|numeric|min:0',
            'total_cost'      => 'nullable|numeric|min:0',
            'input_date'      => 'required|date',
            'provider_id'     => 'nullable|exists:providers,id',
            'synced_to_stock' => 'nullable|boolean',
            'stock_item_name' => 'nullable|string|max:255',
            'notes'           => 'nullable|string|max:500',
        ]);

        $action->execute($cropCycle, $validated);

        return redirect()->route('crop-cycles.show', $cropCycle)->with('success', 'Intrant enregistré.');
    }

    /**
     * Édition du cycle (fiche complète) — page dédiée, à l'image de batches.edit.
     */
    public function edit(CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.cycles.edit', [
            'cycle'     => $cropCycle,
            'campaigns' => CropCampaign::orderByDesc('start_date')->get(['id', 'name', 'year']),
            'employees' => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'statuses'  => CropCycle::EDITABLE_STATUSES,
        ]);
    }

    /** Formulaire de saisie d'une récolte (page dédiée). */
    public function createHarvest(CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if ($cropCycle->isArchived()) {
            return redirect()->route('crop-cycles.show', $cropCycle)
                ->with('error', 'Ce cycle est clôturé : aucune récolte ne peut y être ajoutée.');
        }

        return view('cultures.cycles.harvests.create', [
            'cycle'     => $cropCycle,
            'qualities' => Harvest::QUALITIES,
            'employees' => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    /** Formulaire d'édition d'une récolte (page dédiée). */
    public function editHarvest(CropCycle $cropCycle, Harvest $harvest)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.cycles.harvests.edit', [
            'cycle'     => $cropCycle,
            'harvest'   => $harvest,
            'qualities' => Harvest::QUALITIES,
            'employees' => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    /** Mise à jour d'une récolte (champs descriptifs ; pas de re-synchro stock). */
    public function updateHarvest(Request $request, CropCycle $cropCycle, Harvest $harvest)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'harvest_date'  => 'required|date',
            'quantity'      => 'required|numeric|min:0.001',
            'unit'          => 'nullable|string|max:20',
            'loss_quantity' => 'nullable|numeric|min:0',
            'quality'       => 'nullable|in:' . implode(',', Harvest::QUALITIES),
            'employee_id'   => 'nullable|exists:employees,id',
            'unit_price'    => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string|max:500',
        ]);

        $harvest->update($validated);

        return redirect()->route('crop-cycles.show', $cropCycle)->with('success', 'Récolte mise à jour.');
    }

    /** Formulaire de saisie d'un intrant (page dédiée). */
    public function createInput(CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.cycles.inputs.create', [
            'cycle'      => $cropCycle,
            'inputTypes' => CropInput::TYPES,
            'providers'  => Provider::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Formulaire d'édition d'un intrant (page dédiée). */
    public function editInput(CropCycle $cropCycle, CropInput $input)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.cycles.inputs.edit', [
            'cycle'      => $cropCycle,
            'input'      => $input,
            'inputTypes' => CropInput::TYPES,
            'providers'  => Provider::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Mise à jour d'un intrant (champs descriptifs ; pas de re-synchro stock). */
    public function updateInput(Request $request, CropCycle $cropCycle, CropInput $input)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'type'        => 'required|in:' . implode(',', array_keys(CropInput::TYPES)),
            'name'        => 'required|string|max:255',
            'quantity'    => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:20',
            'unit_cost'   => 'nullable|numeric|min:0',
            'total_cost'  => 'nullable|numeric|min:0',
            'input_date'  => 'required|date',
            'provider_id' => 'nullable|exists:providers,id',
            'notes'       => 'nullable|string|max:500',
        ]);

        $input->update($validated);

        return redirect()->route('crop-cycles.show', $cropCycle)->with('success', 'Intrant mis à jour.');
    }

    public function destroy(CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if ($cropCycle->harvests()->exists()) {
            return back()->with('error', 'Impossible de supprimer un cycle ayant des récoltes enregistrées.');
        }

        $cropCycle->plot()->update(['status' => Plot::STATUS_DISPONIBLE]);
        $cropCycle->delete();

        return redirect()->route('crop-cycles.index')->with('success', 'Cycle supprimé.');
    }

    public function destroyHarvest(CropCycle $cropCycle, Harvest $harvest)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $harvest->delete();

        return back()->with('success', 'Récolte supprimée.');
    }

    public function destroyInput(CropCycle $cropCycle, CropInput $input)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $input->delete();

        return back()->with('success', 'Intrant supprimé.');
    }
}
