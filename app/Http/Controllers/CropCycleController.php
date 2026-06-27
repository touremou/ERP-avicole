<?php

namespace App\Http\Controllers;

use App\Actions\Crop\RecordCropInput;
use App\Actions\Crop\RecordHarvest;
use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropInput;
use App\Models\CropProtocol;
use App\Models\CropProtocolCompletion;
use App\Models\CropProtocolItem;
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

        $plots = Plot::whereNotIn('status', [Plot::STATUS_INACTIVE])
            ->withSum(['cropCycles as used_ha' => function ($query) {
                $query->whereIn('status', CropCycle::IN_PROGRESS_STATUSES);
            }], 'area_used_ha')
            ->orderBy('name')
            ->get()
            ->each(function ($plot) {
                $plot->remaining_ha = max(0.0, (float) $plot->area_ha - (float) ($plot->used_ha ?? 0));
            })
            ->filter(fn ($plot) => $plot->remaining_ha > 0)
            ->values();

        $plotData = $plots->keyBy('id')->map(fn ($p) => [
            'area_ha'      => (float) $p->area_ha,
            'remaining_ha' => (float) $p->remaining_ha,
        ])->toArray();

        return view('cultures.cycles.create', [
            'plots'     => $plots,
            'plotData'  => $plotData,
            'employees' => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'campaigns' => CropCampaign::where('status', '!=', CropCampaign::STATUS_CLOTUREE)->orderByDesc('start_date')->get(['id', 'name', 'year']),
            'species'   => CropSpecies::active()->with('varieties:id,crop_species_id,name,cycle_days,avg_yield_tha')->orderBy('name')->get(),
            'protocols' => CropProtocol::active()->orderBy('name')->get(['id', 'name', 'crop_name']),
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
            'crop_protocol_id'       => 'nullable|exists:crop_protocols,id',
            'employee_id'            => 'nullable|exists:employees,id',
            'code'                   => 'nullable|string|max:50',
            'crop_name'              => 'required|string|max:255',
            'variety'                => 'nullable|string|max:255',
            'area_used_ha'           => 'required|numeric|min:0.001',
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

        $plot = Plot::findOrFail($validated['plot_id']);
        $usedByOthers = (float) CropCycle::where('plot_id', $plot->id)
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->sum('area_used_ha');
        $remaining = max(0.0, (float) $plot->area_ha - $usedByOthers);
        if ((float) $validated['area_used_ha'] > $remaining + 0.0001) {
            return back()->withInput()->withErrors([
                'area_used_ha' => sprintf(
                    'Surface demandée (%.2f ha) dépasse la surface disponible sur cette parcelle (%.2f ha restant sur %.2f ha total).',
                    $validated['area_used_ha'], $remaining, (float) $plot->area_ha
                )
            ]);
        }

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
            'protocol.items',
            'harvests.employee:id,first_name,last_name',
            'inputs.provider:id,name',
            'protocolCompletions.completedBy:id,name',
        ]);

        // Conseils agronomiques (uniquement pour un cycle non archivé).
        $advisories = [];
        $schedule = [];
        if (! $cropCycle->isArchived()) {
            $advisor = new \App\Services\CropAdvisorService();
            $advisories = array_merge(
                $advisor->cycleRisks($cropCycle),
                $cropCycle->plot ? $advisor->weatherAlerts($cropCycle->plot) : []
            );

            // Itinéraire technique : calendrier projeté + alertes (retard / dû).
            if ($cropCycle->protocol) {
                $protocolService = new \App\Services\CropProtocolAlertService();
                $schedule   = $protocolService->getCycleSchedule($cropCycle);
                $advisories = array_merge($advisories, $protocolService->getCycleAlerts($cropCycle));
            }
        }

        // Plan de suivi & conseils du cycle (fenêtre de semis, récolte recommandée…).
        $monitoring = (new \App\Services\CropAdvisorService())->monitoringPlan($cropCycle);

        return view('cultures.cycles.show', [
            'cycle'      => $cropCycle,
            'advisories' => $advisories,
            'schedule'   => $schedule,
            'monitoring' => $monitoring,
            'qualities'  => Harvest::QUALITIES,
            'inputTypes' => CropInput::TYPES,
            'employees'  => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'providers'  => Provider::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Valide (marque « fait ») une étape de l'itinéraire technique du cycle.
     *
     * Idempotent : revalider une étape déjà validée ne crée pas de doublon
     * (updateOrCreate sur la clé cycle × étape) et rafraîchit horodatage/auteur.
     */
    public function completeStep(Request $request, CropCycle $cropCycle, CropProtocolItem $item)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        // L'étape doit appartenir au protocole rattaché à ce cycle.
        if (! $cropCycle->crop_protocol_id || $item->crop_protocol_id !== $cropCycle->crop_protocol_id) {
            return back()->with('error', 'Cette étape n\'appartient pas à l\'itinéraire du cycle.');
        }

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        CropProtocolCompletion::updateOrCreate(
            ['crop_cycle_id' => $cropCycle->id, 'crop_protocol_item_id' => $item->id],
            [
                'completed_at' => now(),
                'completed_by' => $request->user()?->id,
                'notes'        => $data['notes'] ?? null,
            ]
        );

        return back()->with('success', "Étape « {$item->action_name} » validée.");
    }

    /** Annule la validation d'une étape (réouvre le suivi). */
    public function uncompleteStep(CropCycle $cropCycle, CropProtocolItem $item)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        CropProtocolCompletion::where('crop_cycle_id', $cropCycle->id)
            ->where('crop_protocol_item_id', $item->id)
            ->delete();

        return back()->with('success', "Validation de l'étape « {$item->action_name} » annulée.");
    }

    public function update(Request $request, CropCycle $cropCycle)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'campaign_id'            => 'nullable|exists:crop_campaigns,id',
            'crop_protocol_id'       => 'nullable|exists:crop_protocols,id',
            'crop_name'              => 'required|string|max:255',
            'variety'                => 'nullable|string|max:255',
            'employee_id'            => 'nullable|exists:employees,id',
            'area_used_ha'           => 'required|numeric|min:0.001',
            'planting_date'          => 'required|date',
            'expected_harvest_date'  => 'nullable|date|after_or_equal:planting_date',
            'seed_quantity'          => 'nullable|numeric|min:0',
            'seed_unit'              => 'nullable|string|max:20',
            'expected_yield_kg'      => 'nullable|numeric|min:0',
            'total_acquisition_cost' => 'nullable|numeric|min:0',
            'additional_costs'       => 'nullable|numeric|min:0',
            'status'                 => 'required|in:' . implode(',', CropCycle::EDITABLE_STATUSES),
            'notes'                  => 'nullable|string|max:1000',
        ]);

        if (in_array($validated['status'], CropCycle::IN_PROGRESS_STATUSES)) {
            $plot = $cropCycle->plot;
            $usedByOthers = (float) CropCycle::where('plot_id', $plot->id)
                ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
                ->where('id', '!=', $cropCycle->id)
                ->sum('area_used_ha');
            $remaining = max(0.0, (float) $plot->area_ha - $usedByOthers);
            if ((float) $validated['area_used_ha'] > $remaining + 0.0001) {
                return back()->withInput()->withErrors([
                    'area_used_ha' => sprintf(
                        'Surface (%.2f ha) dépasse la surface disponible sur la parcelle (%.2f ha disponible).',
                        $validated['area_used_ha'], $remaining
                    )
                ]);
            }
        }

        $cropCycle->update($validated);

        // À la clôture/abandon, on libère la parcelle seulement si aucun autre cycle actif.
        if (in_array($cropCycle->status, CropCycle::STATUS_ARCHIVED, true)) {
            $cropCycle->update(['closing_date' => $cropCycle->closing_date ?? now()]);
            $hasOtherActive = CropCycle::where('plot_id', $cropCycle->plot_id)
                ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
                ->where('id', '!=', $cropCycle->id)
                ->exists();
            if (!$hasOtherActive) {
                $cropCycle->plot()->update(['status' => Plot::STATUS_DISPONIBLE]);
            }
        }

        return redirect()->route('crop-cycles.show', $cropCycle)
            ->with('success', 'Cycle de culture mis à jour.');
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
            'net_weight_kg'   => 'nullable|numeric|min:0',
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

        // Surface mobilisable pour CE cycle = surface parcelle − surfaces des AUTRES cycles actifs.
        $usedByOthers = (float) CropCycle::where('plot_id', $cropCycle->plot_id)
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->where('id', '!=', $cropCycle->id)
            ->sum('area_used_ha');
        $maxAreaHa = max(0.0, (float) $cropCycle->plot->area_ha - $usedByOthers);

        return view('cultures.cycles.edit', [
            'cycle'     => $cropCycle,
            'campaigns' => CropCampaign::orderByDesc('start_date')->get(['id', 'name', 'year']),
            'employees' => Employee::where('status', 'Actif')->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'statuses'  => CropCycle::EDITABLE_STATUSES,
            'species'   => CropSpecies::active()->with('varieties:id,crop_species_id,name,cycle_days,avg_yield_tha')->orderBy('name')->get(),
            'protocols' => CropProtocol::active()->orderBy('name')->get(['id', 'name', 'crop_name']),
            'maxAreaHa' => $maxAreaHa,
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
            'net_weight_kg' => 'nullable|numeric|min:0',
            'loss_quantity' => 'nullable|numeric|min:0',
            'quality'       => 'nullable|in:' . implode(',', Harvest::QUALITIES),
            'employee_id'   => 'nullable|exists:employees,id',
            'unit_price'    => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string|max:500',
        ]);

        // Poids net non saisi mais récolte en kg → on le recale sur la quantité.
        if (($validated['net_weight_kg'] ?? null) === null
            && strtolower($validated['unit'] ?? 'kg') === 'kg') {
            $validated['net_weight_kg'] = $validated['quantity'];
        }

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

        // Recalculate total_cost if quantity and unit_cost are provided but total_cost is not.
        if (!isset($validated['total_cost']) || $validated['total_cost'] === null) {
            $qty = (float) ($validated['quantity'] ?? $input->quantity);
            $unitCost = (float) ($validated['unit_cost'] ?? $input->unit_cost);
            if ($qty > 0 && $unitCost > 0) {
                $validated['total_cost'] = $qty * $unitCost;
            }
        }

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

        $plot = $cropCycle->plot;
        $cropCycle->delete();
        if (!$plot->isOccupied()) {
            $plot->update(['status' => Plot::STATUS_DISPONIBLE]);
        }

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
