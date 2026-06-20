<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\Plot;
use Illuminate\Support\Facades\Gate;

/**
 * Pilotage du module Production Végétale : vue d'ensemble parcelles / cycles /
 * récoltes (équivalent du dashboard Provenderie côté aliment).
 */
class CultureDashboardController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $stats = [
            'plots_total'     => Plot::count(),
            'plots_occupied'  => Plot::where('status', Plot::STATUS_EN_CULTURE)->count(),
            'cycles_active'   => CropCycle::inProgress()->count(),
            'area_cultivated' => (float) CropCycle::inProgress()->sum('area_used_ha'),
            'harvest_30d'     => (float) Harvest::where('harvest_date', '>=', now()->subDays(30))->sum('quantity'),
        ];

        // Cycles en cours (semés ou en récolte), du plus ancien semis au plus récent.
        $activeCycles = CropCycle::inProgress()
            ->with(['plot:id,name', 'harvests:id,crop_cycle_id,quantity'])
            ->orderBy('planting_date')
            ->take(12)
            ->get();

        // Récoltes récentes.
        $recentHarvests = Harvest::with('cropCycle:id,crop_name,code')
            ->orderByDesc('harvest_date')
            ->take(8)
            ->get();

        // Calendrier cultural : récoltes prévues sous 14 jours (retards compris).
        $dueCycles = CropCycle::dueForHarvest(14)
            ->with('plot:id,name')
            ->orderBy('expected_harvest_date')
            ->get();

        return view('cultures.dashboard', compact('stats', 'activeCycles', 'recentHarvests', 'dueCycles'));
    }
}
