<?php

namespace App\Http\Controllers;

use App\Models\CropCalendarEvent;
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
 *
 * Toutes les données (Overview, Calendar, Catalogue, Weather) sont chargées
 * dans index() et passées à la vue cultures.dashboard via l'onglet actif.
 */
class CultureDashboardController extends Controller
{
    /**
     * Hub principal – charge les données des 4 onglets en une seule requête
     * et passe l'onglet actif à la vue.
     */
    public function index(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès restreint.');
        }

        $activeTab = $request->get('tab', 'overview');

        // ── ONGLET OVERVIEW ──────────────────────────────────────────────────

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

        // Feed consolidé d'alertes agronomiques (risques semis/récolte) sur les
        // cycles en cours, limité aux sévérités élevées.
        $advisor = new \App\Services\CropAdvisorService();
        $agronomicAlerts = [];
        foreach ($activeCycles as $c) {
            foreach ($advisor->cycleRisks($c) as $a) {
                if (in_array($a['severity'], ['critique', 'attention'])) {
                    $agronomicAlerts[] = $a + ['cycle' => $c];
                }
            }
        }
        $agronomicAlerts = array_slice($agronomicAlerts, 0, 8);

        // Récoltes récentes.
        $recentHarvests = Harvest::with('cropCycle:id,crop_name,code')
            ->orderByDesc('harvest_date')
            ->take(8)
            ->get();

        // Cycles avec récolte prévue sous 14 jours (retards inclus).
        $dueCycles = CropCycle::dueForHarvest(14)
            ->with('plot:id,name')
            ->orderBy('expected_harvest_date')
            ->get();

        // Campagne en cours (la plus récente non clôturée).
        $activeCampaign = CropCampaign::where('status', '!=', CropCampaign::STATUS_CLOTUREE)
            ->withCount('cycles')
            ->orderByDesc('start_date')
            ->first();

        // Suggestions de culture pour quelques parcelles libres / partiellement
        // libres : on propose la meilleure recommandation par parcelle (ou null).
        $plotSuggestions = [];
        $freePlots = Plot::whereIn('status', [Plot::STATUS_DISPONIBLE, Plot::STATUS_JACHERE])
            ->with('farm:id,region')
            ->orderBy('name')
            ->take(4)
            ->get();
        foreach ($freePlots as $fp) {
            $recos = $advisor->recommendCropsForPlot($fp, 1);
            $plotSuggestions[] = [
                'plot' => $fp,
                'top'  => $recos[0] ?? null,
            ];
        }

        // Répartition de la surface emblavée par culture (cycles en cours).
        $cropMix = CropCycle::inProgress()
            ->selectRaw('crop_name, SUM(area_used_ha) as area, COUNT(*) as cycles')
            ->groupBy('crop_name')
            ->orderByDesc('area')
            ->take(6)
            ->get();

        // ── ONGLET CALENDAR ──────────────────────────────────────────────────

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

        // Construction d'une ligne par cycle : 12 booléens (mois occupé) + flags semis/récolte.
        $calendarRows = $cycles->map(function (CropCycle $c) use ($year) {
            $start = $c->planting_date ? Carbon::parse($c->planting_date) : null;
            $end   = $c->closing_date
                ? Carbon::parse($c->closing_date)
                : ($c->expected_harvest_date ? Carbon::parse($c->expected_harvest_date) : null);

            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $monthStart = Carbon::create($year, $m, 1)->startOfMonth();
                $monthEnd   = $monthStart->copy()->endOfMonth();
                $occupied   = $start
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

        $calendarYears = range(now()->year + 1, now()->year - 3);

        // ── ÉVÉNEMENTS CALENDAIRES ───────────────────────────────────────────

        $calendarEvents = CropCalendarEvent::with('cropCycle:id,crop_name,code')
            ->where(function ($q) use ($year) {
                $q->whereYear('event_date', $year)
                  ->orWhereYear('end_date', $year);
            })
            ->orderBy('event_date')
            ->get();

        // ── ONGLET WEATHER ───────────────────────────────────────────────────

        $weatherMonth  = $request->get('weatherMonth', now()->format('Y-m'));
        $weatherPlotId = $request->get('weatherPlotId');

        $weatherQuery = WeatherReading::forMonth($weatherMonth)
            ->orderBy('reading_date');

        if ($weatherPlotId) {
            $weatherQuery->where('plot_id', $weatherPlotId);
        }

        $weatherReadings = $weatherQuery->get();

        $weatherStats = [
            'rainfall_total' => (float) $weatherReadings->sum('rainfall_mm'),
            'rainfall_avg'   => $weatherReadings->avg('rainfall_mm') ? round((float) $weatherReadings->avg('rainfall_mm'), 1) : 0.0,
            't_max_avg'      => $weatherReadings->avg('temperature_max') ? round((float) $weatherReadings->avg('temperature_max'), 1) : 0.0,
            'count'          => $weatherReadings->count(),
        ];

        $plots = Plot::orderBy('name')->get(['id', 'name']);

        // ── VUE ──────────────────────────────────────────────────────────────

        return view('cultures.dashboard', compact(
            // meta
            'activeTab',
            // overview
            'stats', 'activeCycles', 'recentHarvests', 'dueCycles', 'activeCampaign', 'cropMix', 'agronomicAlerts', 'plotSuggestions',
            // calendar
            'calendarRows', 'year', 'calendarYears', 'calendarEvents',
            // weather
            'weatherReadings', 'weatherStats', 'weatherMonth', 'weatherPlotId', 'plots',
        ));
    }

    /**
     * Redirige vers le hub dashboard sur l'onglet calendrier, en conservant
     * le paramètre d'année si présent.
     */
    public function calendar(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $params = ['tab' => 'calendar'];

        if ($request->has('year')) {
            $params['year'] = $request->get('year');
        }

        return redirect()->route('cultures.dashboard', $params);
    }
}
