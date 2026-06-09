<?php

namespace App\Http\Controllers;

use App\Models\HealthCheck;
use App\Models\Batch;
use App\Models\Building;
use App\Http\Requests\Health\StoreHealthCheckRequest;
use App\Http\Requests\Health\UpdateHealthCheckRequest;
use App\Actions\Health\UpdateHealthIntervention;
use App\Actions\Health\RecordHealthIntervention;
use App\Services\SanitaryAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HealthController extends Controller
{
    /**
     * Registre Sanitaire et Alertes
     */

    public function index(Request $request, SanitaryAlertService $alertService)
    {
        // 1. Sécurité
        if (Gate::denies('elevage.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint au module Élevage.');

        // 2. Requête filtrée
        $query = HealthCheck::with(['batch.building']);
        
        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // 3. Récupération des données
        $checks = $query->orderBy('intervention_date', 'desc')->paginate((int) setting('general.items_per_page', 20))->withQueryString();
        $batches = Batch::active()->live()->get();
        //$batches = Batch::where('status', 'Actif')->get();

        // 4. Délégation des alertes au Service dédié
        $alerts = $alertService->getActiveAlerts();

        // 💡 5. CALCUL POUR LE WIDGET ROUGE (Incidents en attente)
        $pendingIncidentsCount = \App\Models\HealthIncident::where('status', 'en_attente')->count();

        // On ajoute $pendingIncidentsCount à la liste des données envoyées à la vue
        return view('health.index', compact('checks', 'batches', 'alerts', 'pendingIncidentsCount'));
    }

    public function create(Request $request)
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');
        return view('health.create', [
            'batches'           => Batch::where('status', 'Actif')
                                        ->whereHas('building', fn($q) => $q->physical())
                                        ->orderBy('code')
                                        ->get(),
            'selected_batch_id' => $request->query('batch_id'),
            'prefill_type'      => $request->query('type'),
            'prefill_product'   => $request->query('product_name'),
            'prefill_date'      => $request->query('intervention_date'),
        ]);
    }

    public function store(StoreHealthCheckRequest $request, RecordHealthIntervention $action) 
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');
        $action->execute($request->validated());
        
        return redirect()->route('health.index')->with('success', "Intervention Sanitaire validée pour le lot.");
    }

    public function edit(HealthCheck $health)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');
        return view('health.edit', [
            'health'    => $health,
            'batches'   => Batch::where('status', 'Actif')
                                ->whereHas('building', fn($q) => $q->physical())
                                ->with('building')
                                ->orderBy('code')
                                ->get(),
            'buildings' => Building::physical()->orderBy('name')->get(),
        ]);
    }

    /**
     * Mise à jour d'une intervention sanitaire existante
     */
    public function update(UpdateHealthCheckRequest $request, HealthCheck $health, UpdateHealthIntervention $action)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        // 1. La sécurité et la validation sont gérées par UpdateHealthCheckRequest
        // 2. La transaction et l'enregistrement sont gérés par UpdateHealthIntervention
        $action->execute($health, $request->validated());

        return redirect()->route('health.index')->with('success', 'Fiche sanitaire mise à jour avec succès.');
    }

    public function destroy(HealthCheck $health)
    {
        if (Gate::denies('elevage.S')) return back()->with('error', 'Seul un superviseur peut supprimer une intervention.');

        // [INTÉGRATION STOCK FUTURE] : Restituer le stock de vaccin si annulé.
        $health->delete();
        
        return redirect()->route('health.index')->with('success', 'Intervention retirée du registre.');
    }
}