<?php

namespace App\Http\Controllers;

use App\Models\Plot;
use App\Models\WeatherReading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Relevés météo & pluviométrie (module: cultures).
 */
class WeatherController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $month  = $request->get('month', now()->format('Y-m'));
        $plotId = $request->get('plot_id');

        $query = WeatherReading::forMonth($month)->with('plot:id,name');
        if ($plotId) {
            $query->where('plot_id', $plotId);
        }

        $readings = $query->orderByDesc('reading_date')->get();

        $stats = [
            'rainfall_total' => (float) $readings->sum('rainfall_mm'),
            'rainfall_avg'   => $readings->count() ? round($readings->avg('rainfall_mm'), 1) : 0,
            't_max_avg'      => $readings->whereNotNull('temperature_max')->count()
                ? round($readings->whereNotNull('temperature_max')->avg('temperature_max'), 1) : 0,
            'humidity_avg'   => $readings->whereNotNull('humidity_pct')->count()
                ? round($readings->whereNotNull('humidity_pct')->avg('humidity_pct')) : 0,
            'count'          => $readings->count(),
        ];

        return view('cultures.weather.index', [
            'readings' => $readings,
            'stats'    => $stats,
            'month'    => $month,
            'plotId'   => $plotId,
            'plots'    => Plot::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'plot_id'         => 'nullable|exists:plots,id',
            'reading_date'    => 'required|date',
            'temperature_min' => 'nullable|numeric|min:-10|max:60',
            'temperature_max' => 'nullable|numeric|min:-10|max:60',
            'humidity_pct'    => 'nullable|numeric|min:0|max:100',
            'rainfall_mm'     => 'nullable|numeric|min:0',
            'wind_kmh'        => 'nullable|numeric|min:0',
            'sunshine_h'      => 'nullable|numeric|min:0|max:24',
            'notes'           => 'nullable|string|max:500',
        ]);

        WeatherReading::create($validated);

        return back()->with('success', 'Relevé météo enregistré.');
    }

    public function destroy(WeatherReading $weather)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $weather->delete();

        return back()->with('success', 'Relevé supprimé.');
    }
}
