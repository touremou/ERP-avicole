<?php

namespace App\Http\Controllers;

use App\Models\CropSpecies;
use App\Models\CropVariety;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Catalogue des cultures (module: cultures) — référentiel des espèces et
 * variétés adaptées au contexte guinéen.
 */
class CropCatalogueController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $species = CropSpecies::withCount('varieties')
            ->orderBy('type')->orderBy('name')
            ->get();

        // Regroupement par type en respectant l'ordre de référence des TYPES.
        $grouped = collect(CropSpecies::TYPES)
            ->map(fn ($meta, $key) => $species->where('type', $key)->values())
            ->filter(fn ($list) => $list->isNotEmpty());

        $stats = [
            'species'   => $species->count(),
            'varieties' => $species->sum('varieties_count'),
            'families'  => $grouped->count(),
        ];

        return view('cultures.catalogue.index', compact('grouped', 'stats'));
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.catalogue.create', ['types' => CropSpecies::TYPES]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'type'           => 'required|in:' . implode(',', array_keys(CropSpecies::TYPES)),
            'name'           => 'required|string|max:255',
            'local_name'     => 'nullable|string|max:255',
            'family'         => 'nullable|string|max:255',
            'cycle_days_min' => 'nullable|integer|min:1|max:1000',
            'cycle_days_max' => 'nullable|integer|min:1|max:1000|gte:cycle_days_min',
            'avg_yield_tha'  => 'nullable|numeric|min:0',
            'description'    => 'nullable|string|max:1000',
        ]);

        $species = CropSpecies::create($validated);

        return redirect()->route('crop-catalogue.show', $species)
            ->with('success', "Culture « {$species->name} » ajoutée au catalogue.");
    }

    public function show(CropSpecies $cropCatalogue)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $cropCatalogue->load(['varieties' => fn ($q) => $q->orderBy('name')]);

        return view('cultures.catalogue.show', ['species' => $cropCatalogue]);
    }

    public function edit(CropSpecies $cropCatalogue)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropCatalogue->load(['varieties' => fn ($q) => $q->orderBy('name')]);

        return view('cultures.catalogue.edit', [
            'species' => $cropCatalogue,
            'types'   => CropSpecies::TYPES,
        ]);
    }

    public function update(Request $request, CropSpecies $cropCatalogue)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'type'           => 'required|in:' . implode(',', array_keys(CropSpecies::TYPES)),
            'name'           => 'required|string|max:255',
            'local_name'     => 'nullable|string|max:255',
            'family'         => 'nullable|string|max:255',
            'cycle_days_min' => 'nullable|integer|min:1|max:1000',
            'cycle_days_max' => 'nullable|integer|min:1|max:1000|gte:cycle_days_min',
            'avg_yield_tha'  => 'nullable|numeric|min:0',
            'description'    => 'nullable|string|max:1000',
            'is_active'      => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $cropCatalogue->update($validated);

        return redirect()->route('crop-catalogue.show', $cropCatalogue)->with('success', 'Culture mise à jour.');
    }

    public function storeVariety(Request $request, CropSpecies $cropCatalogue)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'cycle_days'    => 'nullable|integer|min:1|max:1000',
            'avg_yield_tha' => 'nullable|numeric|min:0',
            'cycle_type'    => 'nullable|string|max:50',
            'notes'         => 'nullable|string|max:500',
        ]);

        $cropCatalogue->varieties()->create($validated);

        return back()->with('success', 'Variété ajoutée.');
    }

    public function updateVariety(Request $request, CropVariety $variety)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'cycle_days'    => 'nullable|integer|min:1|max:1000',
            'avg_yield_tha' => 'nullable|numeric|min:0',
            'cycle_type'    => 'nullable|string|max:50',
            'notes'         => 'nullable|string|max:500',
        ]);

        $variety->update($validated);

        return back()->with('success', 'Variété mise à jour.');
    }

    public function destroyVariety(CropVariety $variety)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $variety->delete();

        return back()->with('success', 'Variété supprimée.');
    }
}
