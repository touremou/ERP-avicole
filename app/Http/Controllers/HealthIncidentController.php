<?php

namespace App\Http\Controllers;

use App\Models\HealthIncident;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class HealthIncidentController extends Controller
{
    public function index()
    {
        if (Gate::denies('elevage.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        // 1. On compte les cas en attente
        $pendingIncidentsCount = \App\Models\HealthIncident::where('status', 'en_attente')->count();

        // 2. On récupère la liste paginée
        $incidents = \App\Models\HealthIncident::with(['building', 'user'])
            ->orderByRaw("FIELD(status, 'en_attente', 'diagnostique', 'resolu')")
            ->orderBy('created_at', 'desc')
            ->paginate((int) setting('general.items_per_page', 20));

        // 3. 💡 N'oubliez pas d'ajouter $pendingIncidentsCount dans le compact !
        return view('health.incidents', compact('incidents', 'pendingIncidentsCount'));
    }

    public function store(Request $request)
    {
        if (Gate::denies('elevage.C')) return back()->with('error', 'Action non autorisée.');
        // 1. Validation des données de l'incident
        $validated = $request->validate([
            'batch_id'        => 'required|exists:batches,id',
            'mortality_count' => 'required|integer|min:1',
            'symptoms'        => 'required|string|max:1000',
            'photo'           => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        // 2. Traitement de la photo d'autopsie
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('autopsies', 'public');
        }

        $batch = Batch::findOrFail($validated['batch_id']);

        // 3. Enregistrement strict du registre qualitatif (Pas de déduction d'inventaire ici)
        HealthIncident::create([
            'building_id'     => $batch->building_id,
            'user_id'         => Auth::id(),
            'incident_date'   => now()->toDateString(),
            'mortality_count' => $validated['mortality_count'], // Pour information vétérinaire
            'symptoms'        => $validated['symptoms'],
            'photo_path'      => $photoPath,
        ]);

        // 4. Message de succès avec un rappel UX pour l'agent
        return back()->with('success', 'Alerte sanitaire transmise au vétérinaire. Pensez à déduire cette mortalité lors du relevé quotidien ce soir.');
    }
    public function diagnose(Request $request, HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        // 1. Validation avec les BONS noms de champs du formulaire
        $request->validate([
            'suspected_disease' => 'required|string|max:255',
            'vet_prescription'  => 'nullable|string', // nullable car pas obligatoire selon votre HTML
        ]);

        // 2. Mise à jour dans la base de données
        $incident->update([
            'suspected_disease' => $request->suspected_disease,
            'vet_prescription'  => $request->vet_prescription,
            'status'            => 'diagnostique', // 💡 Très important : on passe le statut à "diagnostiqué"
        ]);

        // 3. Redirection
        return back()->with('success', 'Le diagnostic vétérinaire a été enregistré avec succès.');
    }

    public function resolve(HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        $incident->update(['status' => 'resolu']);

        return back()->with('success', 'Le cas sanitaire a été marqué comme résolu et archivé.');
    }

    public function closeFast(Request $request, HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        // 1. Validation de la justification obligatoire
        $request->validate([
            'justification' => 'required|string|max:1000',
        ]);

        // 2. On clôture directement l'incident en précisant que c'est non-médical
        $incident->update([
            'status'            => 'resolu',
            'suspected_disease' => 'Cause non médicale',
            'vet_prescription'  => 'Justification technique : ' . $request->justification,
        ]);

        return back()->with('success', 'L\'incident a été clôturé (Cause non médicale).');
    }
}