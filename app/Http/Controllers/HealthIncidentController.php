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
            'severity'        => 'nullable|in:mineur,modere,critique',
            'symptoms'        => 'required|string|max:1000',
            'photo'           => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        // 2. Traitement de la photo d'autopsie
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('autopsies', 'public');
        }

        $batch = Batch::findOrFail($validated['batch_id']);

        // 3. Enregistrement du registre qualitatif (rattaché au LOT, pas seulement
        //    au bâtiment — traçabilité par bande). Pas de déduction d'inventaire ici.
        $incident = HealthIncident::create([
            'building_id'     => $batch->building_id,
            'batch_id'        => $batch->id,
            'user_id'         => Auth::id(),
            'incident_date'   => now()->toDateString(),
            'mortality_count' => $validated['mortality_count'],
            'severity'        => $validated['severity'] ?? HealthIncident::SEVERITY_MODERATE,
            'symptoms'        => $validated['symptoms'],
            'photo_path'      => $photoPath,
            'status'          => HealthIncident::STATUS_PENDING,
        ]);

        // 4. Alerte temps réel (escaladée si gravité critique).
        try {
            app(\App\Services\NotificationHub::class)->alertHealthIncident($incident->load(['batch', 'building']));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Alerte incident sanitaire non envoyée : {$e->getMessage()}");
        }

        // 5. Message de succès avec un rappel UX pour l'agent
        return back()->with('success', 'Alerte sanitaire transmise au vétérinaire. Pensez à déduire cette mortalité lors du relevé quotidien ce soir.');
    }
    public function diagnose(Request $request, HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        // 1. Validation (coût de traitement optionnel, pour l'imputation au lot).
        $validated = $request->validate([
            'suspected_disease' => 'required|string|max:255',
            'vet_prescription'  => 'nullable|string',
            'treatment_cost'    => 'nullable|numeric|min:0',
        ]);

        // 2. Diagnostic + traçabilité (qui / quand).
        $incident->update([
            'suspected_disease' => $validated['suspected_disease'],
            'vet_prescription'  => $validated['vet_prescription'] ?? null,
            'treatment_cost'    => round((float) ($validated['treatment_cost'] ?? 0), 2),
            'status'            => HealthIncident::STATUS_DIAGNOSED,
            'diagnosed_by'      => Auth::id(),
            'diagnosed_at'      => now(),
        ]);

        return back()->with('success', 'Le diagnostic vétérinaire a été enregistré avec succès.');
    }

    public function resolve(Request $request, HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        // Note de résolution OBLIGATOIRE : un incident ne se clôt pas sans
        // conclusion (issue, mesures prises) — traçabilité sanitaire.
        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:1000',
        ]);

        $incident->update([
            'status'                => HealthIncident::STATUS_RESOLVED,
            'resolution_notes'      => $validated['resolution_notes'],
            'resolved_by'           => Auth::id(),
            'resolved_at'           => now(),
            // La résolution lève la quarantaine si elle était active.
            'is_quarantined'        => false,
            'quarantine_ended_at'   => $incident->is_quarantined ? now() : $incident->quarantine_ended_at,
        ]);

        return back()->with('success', 'Le cas sanitaire a été marqué comme résolu et archivé.');
    }

    public function closeFast(Request $request, HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        $request->validate([
            'justification' => 'required|string|max:1000',
        ]);

        // Clôture non médicale : traçabilité du résolveur conservée.
        $incident->update([
            'status'            => HealthIncident::STATUS_RESOLVED,
            'suspected_disease' => 'Cause non médicale',
            'vet_prescription'  => 'Justification technique : ' . $request->justification,
            'resolution_notes'  => 'Clôture rapide (non médicale) : ' . $request->justification,
            'resolved_by'       => Auth::id(),
            'resolved_at'       => now(),
            'is_quarantined'    => false,
        ]);

        return back()->with('success', 'L\'incident a été clôturé (Cause non médicale).');
    }

    /** Place ou lève la quarantaine du lot concerné par l'incident. */
    public function toggleQuarantine(HealthIncident $incident)
    {
        if (Gate::denies('elevage.M')) return back()->with('error', 'Action non autorisée.');

        if ($incident->is_quarantined) {
            $incident->update(['is_quarantined' => false, 'quarantine_ended_at' => now()]);
            return back()->with('success', 'Quarantaine levée.');
        }

        $incident->update([
            'is_quarantined'        => true,
            'quarantine_started_at' => now(),
            'quarantine_ended_at'   => null,
        ]);

        return back()->with('success', 'Lot placé en quarantaine sanitaire.');
    }
}