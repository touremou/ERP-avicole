<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\Plot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Parcelles agricoles (module: cultures).
 */
class PlotController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $plots = Plot::withCount(['cropCycles', 'activeCycle'])
            ->orderBy('name')
            ->paginate((int) setting('general.items_per_page', 20));

        return view('cultures.plots.index', [
            'plots'    => $plots,
            'statuses' => Plot::STATUSES,
        ]);
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.plots.create', ['statuses' => Plot::STATUSES]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'nullable|string|max:50',
            'area_ha'         => 'required|numeric|min:0.001',
            'location'        => 'nullable|string|max:255',
            'soil_type'       => 'nullable|string|max:100',
            'agro_zone'       => 'nullable|string|max:40',
            'irrigation_type' => 'nullable|string|max:100',
            'status'          => 'nullable|in:' . implode(',', Plot::STATUSES),
            'notes'           => 'nullable|string|max:1000',
        ]);

        $plot = Plot::create($validated);

        return redirect()->route('plots.show', $plot)
            ->with('success', "Parcelle « {$plot->name} » créée.");
    }

    public function show(Plot $plot)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $plot->load([
            'cropCycles' => fn ($q) => $q->with('harvests:id,crop_cycle_id,quantity')->orderByDesc('planting_date'),
        ]);

        $advisor = new \App\Services\CropAdvisorService();
        $rotation = $advisor->rotationSuggestions($plot);
        $recommendations = $advisor->recommendCropsForPlot($plot);

        return view('cultures.plots.show', [
            'plot'            => $plot,
            'rotation'        => $rotation,
            'recommendations' => $recommendations,
        ]);
    }

    public function edit(Plot $plot)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.plots.edit', [
            'plot'     => $plot,
            'statuses' => Plot::STATUSES,
        ]);
    }

    public function update(Request $request, Plot $plot)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'nullable|string|max:50',
            'area_ha'         => 'required|numeric|min:0.001',
            'location'        => 'nullable|string|max:255',
            'soil_type'       => 'nullable|string|max:100',
            'agro_zone'       => 'nullable|string|max:40',
            'irrigation_type' => 'nullable|string|max:100',
            'status'          => 'required|in:' . implode(',', Plot::STATUSES),
            'notes'           => 'nullable|string|max:1000',
        ]);

        // Garde-fou : ne pas réduire la surface en deçà de ce qui est déjà
        // emblavé par les cycles en cours (sinon surface restante incohérente
        // et futurs semis bloqués à tort).
        $usedByActive = (float) $plot->cropCycles()
            ->whereIn('status', CropCycle::IN_PROGRESS_STATUSES)
            ->sum('area_used_ha');
        if ((float) $validated['area_ha'] + 0.0001 < $usedByActive) {
            return back()->withInput()->withErrors([
                'area_ha' => sprintf(
                    'Surface (%.2f ha) inférieure à la surface déjà emblavée par les cycles en cours (%.2f ha).',
                    $validated['area_ha'], $usedByActive
                )
            ]);
        }

        $plot->update($validated);

        return redirect()->route('plots.show', $plot)->with('success', 'Parcelle mise à jour.');
    }

    public function destroy(Plot $plot)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if ($plot->isOccupied()) {
            return back()->with('error', "Impossible de supprimer une parcelle avec un cycle en cours.");
        }

        $plot->delete();

        return redirect()->route('plots.index')->with('success', 'Parcelle supprimée.');
    }
}
