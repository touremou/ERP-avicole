<?php

namespace App\Http\Controllers;

use App\Models\CropCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Campagnes agricoles (module: cultures) — pilotage des saisons culturales.
 */
class CropCampaignController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $year = (int) $request->get('year', now()->year);

        // total_harvested utilise alors son agrégat SQL (1 requête/campagne) au
        // lieu de sommer des cycles chargés sans leurs récoltes (N+1 imbriqué).
        $campaigns = CropCampaign::forYear($year)
            ->withCount('cycles')
            ->orderByDesc('start_date')
            ->get();

        $years = range(now()->year + 1, now()->year - 3);

        return view('cultures.campaigns.index', compact('campaigns', 'year', 'years'));
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.campaigns.create', [
            'seasons'  => CropCampaign::SEASONS,
            'statuses' => CropCampaign::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'code'                => 'nullable|string|max:50',
            'name'                => 'required|string|max:255',
            'year'                => 'required|integer|min:2020|max:2035',
            'season'              => 'required|in:' . implode(',', array_keys(CropCampaign::SEASONS)),
            'start_date'          => 'required|date',
            'end_date_planned'    => 'nullable|date|after_or_equal:start_date',
            'target_production_t' => 'nullable|numeric|min:0',
            'status'              => 'nullable|in:' . implode(',', array_keys(CropCampaign::STATUSES)),
            'notes'               => 'nullable|string|max:1000',
        ]);

        $campaign = CropCampaign::create($validated);

        return redirect()->route('crop-campaigns.show', $campaign)
            ->with('success', "Campagne « {$campaign->name} » créée.");
    }

    public function show(CropCampaign $cropCampaign)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $cropCampaign->load([
            'cycles' => fn ($q) => $q->with(['plot:id,name', 'harvests:id,crop_cycle_id,quantity'])
                ->orderBy('planting_date'),
        ]);

        return view('cultures.campaigns.show', [
            'campaign' => $cropCampaign,
            'statuses' => CropCampaign::STATUSES,
        ]);
    }

    public function update(Request $request, CropCampaign $cropCampaign)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'                => 'required|string|max:255',
            'season'              => 'required|in:' . implode(',', array_keys(CropCampaign::SEASONS)),
            'start_date'          => 'required|date',
            'end_date_planned'    => 'nullable|date|after_or_equal:start_date',
            'target_production_t' => 'nullable|numeric|min:0',
            'status'              => 'required|in:' . implode(',', array_keys(CropCampaign::STATUSES)),
            'notes'               => 'nullable|string|max:1000',
        ]);

        $cropCampaign->update($validated);

        return back()->with('success', 'Campagne mise à jour.');
    }

    public function destroy(CropCampaign $cropCampaign)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        // Les cycles rattachés sont détachés (nullOnDelete via la FK).
        $cropCampaign->delete();

        return redirect()->route('crop-campaigns.index')
            ->with('success', 'Campagne supprimée.');
    }
}
