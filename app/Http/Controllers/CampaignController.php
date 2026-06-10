<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Campagnes saisonnières (Tabaski/Eid, Ramadan, fêtes).
 *
 * Gating : module Élevage (elevage.L/C/M/S), les campagnes pilotant des
 * lots d'engraissement vers une vente groupée datée.
 */
class CampaignController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('elevage.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        // Eager loading des charges pour éviter un N+1 dans les accesseurs KPI
        // (operating_cost somme feedPurchases/healthChecks par lot).
        $campaigns = Campaign::with(['batches.feedPurchases', 'batches.healthChecks'])
            ->orderByRaw("CASE WHEN status = 'cloturee' THEN 1 ELSE 0 END")
            ->orderBy('target_date')
            ->get();

        return view('campaigns.index', compact('campaigns'));
    }

    public function create(): View|RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', 'Création non autorisée.');
        }

        return view('campaigns.create', [
            'nextEidDates' => $this->suggestedEidDates(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', 'Création non autorisée.');
        }

        $data = $this->validateData($request);
        $campaign = Campaign::create($data);

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', "Campagne « {$campaign->name} » créée.");
    }

    public function show(Campaign $campaign): View|RedirectResponse
    {
        if (Gate::denies('elevage.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $campaign->load(['batches.species', 'batches.building', 'batches.feedPurchases', 'batches.healthChecks']);

        // Lots éligibles à rattacher : actifs, de la famille ciblée, non
        // déjà affectés à une campagne.
        $eligibleBatches = Batch::where('status', 'Actif')
            ->whereNull('campaign_id')
            ->whereHas('species', fn ($q) => $q->where('family', $campaign->target_family))
            ->with(['species', 'building'])
            ->orderBy('code')
            ->get();

        return view('campaigns.show', compact('campaign', 'eligibleBatches'));
    }

    public function edit(Campaign $campaign): View|RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Modification non autorisée.');
        }

        return view('campaigns.edit', [
            'campaign'     => $campaign,
            'nextEidDates' => $this->suggestedEidDates(),
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Modification non autorisée.');
        }

        $campaign->update($this->validateData($request));

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Campagne mise à jour.');
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        if (Gate::denies('elevage.S')) {
            return back()->with('error', 'Suppression réservée.');
        }

        // On détache les lots (sans les supprimer) avant de retirer la campagne.
        $campaign->batches()->update(['campaign_id' => null]);
        $campaign->delete();

        return redirect()->route('campaigns.index')
            ->with('success', 'Campagne supprimée (lots détachés, non supprimés).');
    }

    /**
     * Rattache un lot existant à la campagne.
     */
    public function attachBatch(Request $request, Campaign $campaign): RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'batch_id' => 'required|exists:batches,id',
        ]);

        $batch = Batch::findOrFail($validated['batch_id']);

        if ($batch->campaign_id && $batch->campaign_id !== $campaign->id) {
            return back()->with('error', "Le lot {$batch->code} appartient déjà à une autre campagne.");
        }

        $batch->update(['campaign_id' => $campaign->id]);

        return back()->with('success', "Lot {$batch->code} rattaché à la campagne.");
    }

    /**
     * Détache un lot de la campagne (le lot n'est pas supprimé).
     */
    public function detachBatch(Campaign $campaign, Batch $batch): RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if ($batch->campaign_id === $campaign->id) {
            $batch->update(['campaign_id' => null]);
        }

        return back()->with('success', "Lot {$batch->code} détaché de la campagne.");
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'              => 'required|string|max:255',
            'type'             => 'required|in:' . implode(',', array_keys(Campaign::TYPES)),
            'target_family'     => 'required|string|max:30',
            'status'            => 'required|in:' . implode(',', array_keys(Campaign::STATUSES)),
            'start_date'        => 'nullable|date',
            'target_date'       => 'required|date',
            'target_head_count' => 'nullable|integer|min:0',
            'target_budget'     => 'nullable|numeric|min:0',
            'target_sale_price' => 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:2000',
        ]);
    }

    /**
     * Dates approchées d'Eid al-Adha (Tabaski) à venir, pour pré-remplissage.
     */
    private function suggestedEidDates(): array
    {
        $eidDates = ['2026-06-16', '2027-06-06', '2028-05-26', '2029-05-15', '2030-05-05'];
        $today = now()->startOfDay();

        return collect($eidDates)
            ->filter(fn ($d) => \Carbon\Carbon::parse($d)->gte($today))
            ->values()
            ->all();
    }
}
