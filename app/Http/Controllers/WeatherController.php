<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Plot;
use App\Models\WeatherReading;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Relevés météo & pluviométrie (module: cultures).
 */
class WeatherController extends Controller
{
    public function index(Request $request, WeatherService $weather)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $month  = $request->get('month', now()->format('Y-m'));
        $plotId = $request->get('plot_id');

        // Prévisions J+1→J+3 et alertes prédictives pour la ferme courante.
        $farmId   = session('current_farm_id') ?? Farm::defaultId();
        $farm     = $farmId ? Farm::find($farmId) : null;
        $forecast = $farm ? $weather->forecast($farm, 3) : [];
        $forecastAlerts = $farm ? $weather->forecastAlerts($farm, 3) : [];

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
            'readings'       => $readings,
            'stats'          => $stats,
            'month'          => $month,
            'plotId'         => $plotId,
            'plots'          => Plot::orderBy('name')->get(['id', 'name']),
            'forecast'       => $forecast,
            'forecastAlerts' => $forecastAlerts,
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

        return redirect()->route('cultures.dashboard', ['tab' => 'meteo'])->with('success', 'Relevé météo enregistré.');
    }

    /**
     * Récupère la météo du jour (Open-Meteo) et actualise le relevé de la ferme
     * courante — déclenchement manuel depuis l'interface (bouton).
     */
    public function fetchNow(Request $request, WeatherService $weather)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if (! $weather->enabled()) {
            return back()->with('error', 'Le service météo automatique est désactivé.');
        }

        $farmId = session('current_farm_id') ?? Farm::defaultId();
        $farm   = $farmId ? Farm::find($farmId) : null;

        if (! $farm) {
            return back()->with('error', 'Ferme introuvable pour la récupération météo.');
        }

        $date = $request->input('reading_date', now()->toDateString());
        $data = $weather->dailyForFarm($farm, $date);

        if ($data === null) {
            return back()->with('error', "Météo indisponible — renseignez la ville de la ferme « {$farm->name} » ou réessayez plus tard.");
        }

        $weather->storeReading($farm, $date, $data);

        return back()->with('success', "Météo du {$date} récupérée : {$data['temperature_max']}°C, {$data['humidity_pct']}% HR, {$data['rainfall_mm']} mm.");
    }

    public function edit(WeatherReading $weather)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.weather.edit', [
            'reading' => $weather,
            'plots'   => Plot::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, WeatherReading $weather)
    {
        if (Gate::denies('cultures.M')) {
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

        $weather->update($validated);

        return redirect()->route('cultures.dashboard', ['tab' => 'meteo', 'weatherMonth' => $weather->reading_date->format('Y-m')])
            ->with('success', 'Relevé météo mis à jour.');
    }

    public function destroy(WeatherReading $weather)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $weather->delete();

        return redirect()->route('cultures.dashboard', ['tab' => 'meteo'])->with('success', 'Relevé supprimé.');
    }
}
