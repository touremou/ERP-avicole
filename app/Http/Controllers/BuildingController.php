<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Http\Requests\Building\StoreBuildingRequest;
use App\Http\Requests\Building\UpdateBuildingRequest;
use App\Actions\Building\CreateBuilding;
use App\Actions\Building\UpdateBuildingConfig;
use App\Actions\Building\DecommissionBuilding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BuildingController extends Controller
{
    /**
     * Liste des infrastructures (Vue L)
     */
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('elevage.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $buildings = Building::physical() // 💡 Ton code devient très lisible
            ->with(['batches' => function($query) {
                $query->active()->with('dailyChecks');
            }])
            ->orderBy('name')
            ->get();

        return view('buildings.index', compact('buildings'));
    }

    public function create(): View|RedirectResponse
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Privilèges insuffisants.');

        // Liste du parc réel : on exclut le bâtiment virtuel de traçabilité
        // (cf. Building::scopePhysical) et on charge type + capacité pour des
        // indicateurs cohérents — sinon ces colonnes ressortaient à 0/N/A.
        $buildings = Building::physical()
            ->select('id', 'name', 'type', 'capacity', 'status')
            ->orderBy('name')
            ->get();

        return view('buildings.create', compact('buildings'));
    }

    /**
     * Enregistrement (Phase C)
     */
    public function store(StoreBuildingRequest $request, CreateBuilding $action): RedirectResponse
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Privilèges insuffisants.');
        $action->execute($request->validated());

        return redirect()->route('buildings.index')
            ->with('success', 'Bâtiment opérationnel ajouté au référentiel.');
    }

    /**
     * Fiche technique de l'actif (Vue L)
     */
    public function show($id): View
    {
        if (Gate::denies('elevage.L')) return back()->with('error', 'Privilèges insuffisants.');
        $building = Building::with(['batches' => function($query) {
            $query->orderByDesc('arrival_date')->with(['dailyChecks', 'provider']);
        }])->findOrFail($id);
        
        return view('buildings.show', compact('building'));
    }

    public function edit($id): View|RedirectResponse
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Privilèges insuffisants.');

        $building = Building::findOrFail($id);
        $isOccupied = $building->batches()->active()->exists();

        return view('buildings.edit', compact('building', 'isOccupied'));
    }

    /**
     * Mise à jour technique (Phase M)
     */
    public function update(UpdateBuildingRequest $request, Building $building, UpdateBuildingConfig $action): RedirectResponse
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Privilèges insuffisants.');

        // L'action gère l'analyse d'occupation et lève les exceptions si nécessaire
        $action->execute($building, $request->validated());

        return redirect()->route('buildings.show', $building->id)
            ->with('success', 'Configuration technique mise à jour avec succès.');
    }

    /**
     * Sortie du parc industriel (Phase S)
     */
    public function destroy(Building $building, DecommissionBuilding $action): RedirectResponse
    {
        if (Gate::denies('elevage.S')) return back()->with('error', '🔒 Droits Administrateur requis.');

        try {
            $action->execute($building);
            return redirect()->route('buildings.index')->with('success', 'Bâtiment retiré du parc industriel.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Miroir local (IndexedDB) des bâtiments physiques — mode terrain.
     */
    public function getOfflineBuildings(): \Illuminate\Http\JsonResponse
    {
        if (Gate::denies('elevage.L')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(
            Building::physical()->get(['id', 'name', 'type', 'capacity', 'status'])
        );
    }
}