<?php

namespace App\Http\Controllers;

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

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'nullable|string|max:50',
            'area_ha'         => 'required|numeric|min:0',
            'location'        => 'nullable|string|max:255',
            'soil_type'       => 'nullable|string|max:100',
            'irrigation_type' => 'nullable|string|max:100',
            'status'          => 'nullable|in:' . implode(',', Plot::STATUSES),
            'notes'           => 'nullable|string|max:1000',
        ]);

        $plot = Plot::create($validated);

        return redirect()->route('plots.index')
            ->with('success', "Parcelle « {$plot->name} » créée.");
    }

    public function update(Request $request, Plot $plot)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'code'            => 'nullable|string|max:50',
            'area_ha'         => 'required|numeric|min:0',
            'location'        => 'nullable|string|max:255',
            'soil_type'       => 'nullable|string|max:100',
            'irrigation_type' => 'nullable|string|max:100',
            'status'          => 'required|in:' . implode(',', Plot::STATUSES),
            'notes'           => 'nullable|string|max:1000',
        ]);

        $plot->update($validated);

        return back()->with('success', 'Parcelle mise à jour.');
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
