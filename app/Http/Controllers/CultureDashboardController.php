<?php

namespace App\Http\Controllers;

use App\Models\CropCampaign;
use App\Models\CropCycle;
use App\Models\CropTransformation;
use App\Models\Harvest;
use App\Models\Plot;
use App\Models\WeatherReading;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Pilotage du module Production Végétale : vue d'ensemble parcelles / cycles /
 * récoltes / campagnes / transformation / météo (équivalent du dashboard
 * Provenderie côté aliment).
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
            'harvest_ytd'     => (float) Harvest::whereYear('harvest_date', now()->year)->sum('quantity'),
            'transform_30d'   => CropTransformation::where('production_date', '>=', now()->subDays(30))->count(),
            'rainfall_30d'    => (float) WeatherReading::where('reading_date', '>=', now()->subDays(30))->sum('rainfall_mm'),
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

        // Campagne en cours (la plus récente non clôturée).
        $activeCampaign = CropCampaign::where('status', '!=', CropCampaign::STATUS_CLOTUREE)
            ->withCount('cycles')
            ->orderByDesc('start_date')
            ->first();

        // Répartition de la surface emblavée par culture (cycles en cours).
        $cropMix = CropCycle::inProgress()
            ->selectRaw('crop_name, SUM(area_used_ha) as area, COUNT(*) as cycles')
            ->groupBy('crop_name')
            ->orderByDesc('area')
            ->take(6)
            ->get();

        return view('cultures.dashboard', compact(
            'stats', 'activeCycles', 'recentHarvests', 'dueCycles', 'activeCampaign', 'cropMix'
        ));
    }

    /**
     * Calendrier cultural : matrice cultures × mois pour une année donnée. Une
     * cellule est « occupée » si le cycle court sur ce mois (du semis à la
     * récolte prévue/clôture), avec marquage des mois de semis et de récolte.
     */
    public function calendar(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $year = (int) $request->get('year', now()->year);

        // Cycles touchant l'année : semés cette année OU clôturés/à récolter cette année.
        $cycles = CropCycle::with('plot:id,name')
            ->where(function ($q) use ($year) {
                $q->whereYear('planting_date', $year)
                    ->orWhereYear('expected_harvest_date', $year)
                    ->orWhereYear('closing_date', $year);
            })
            ->orderBy('planting_date')
            ->get();

        // Construction d'une ligne par cycle : 12 booléens (mois occupé) + flags.
        $rows = $cycles->map(function (CropCycle $c) use ($year) {
            $start = $c->planting_date ? Carbon::parse($c->planting_date) : null;
            $end   = $c->closing_date
                ? Carbon::parse($c->closing_date)
                : ($c->expected_harvest_date ? Carbon::parse($c->expected_harvest_date) : null);

            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $monthStart = Carbon::create($year, $m, 1)->startOfMonth();
                $monthEnd   = $monthStart->copy()->endOfMonth();
                $occupied = $start
                    && $start->lte($monthEnd)
                    && (! $end || $end->gte($monthStart));
                $months[$m] = [
                    'occupied' => $occupied,
                    'planting' => $start && $start->year === $year && $start->month === $m,
                    'harvest'  => $end && $end->year === $year && $end->month === $m,
                ];
            }

            return [
                'cycle'  => $c,
                'months' => $months,
            ];
        });

        $years = range(now()->year + 1, now()->year - 3);

        return view('cultures.calendar', compact('rows', 'year', 'years'));
    }
}
