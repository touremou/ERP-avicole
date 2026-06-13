<?php

namespace App\Http\Controllers;

use App\Models\Incubation;
use App\Models\Batch;
use App\Models\Incubator;
use App\Http\Requests\Incubation\StartIncubationRequest;
use App\Http\Requests\Incubation\RecordMirageRequest;
use App\Http\Requests\Incubation\RecordHatchRequest;
use App\Actions\Incubation\StartIncubation;
use App\Actions\Incubation\RecordMirage;
use App\Actions\Incubation\RecordHatching;
use App\Actions\Incubation\AbortIncubation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

class IncubationController extends Controller
{
    public function index(Request $request)
    {
        if (Gate::denies('production.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $incubators = Incubator::with(['incubations' => fn($q) => $q->latest()->limit(5)])->get();
        $providers = \App\Models\Provider::orderBy('name')->get();
        $sort = $request->query('sort', 'date');

        // ═══ UNE SEULE DÉFINITION de $query ═══
        $query = Incubation::with(['batch', 'incubator'])
            ->withCount('chickDispatches')
            ->withSum('chickDispatches', 'quantity')
            ->where('status', '!=', 'clos');          // ← 'clos' partout (pas 'termine')

        $incubations = match($sort) {
            'eggs'     => $query->orderByDesc('eggs_count')->get(),
            'progress' => $query->orderBy('start_date')->get(),
            default    => $query->orderBy('hatch_date_expected')->get(),
        };

        // Lots éligibles à l'incubation : pondeurs/reproducteurs avicoles
        // uniquement (le slug "reproducteur" est partagé avec les ruminants,
        // qui ne pondent pas — on s'appuie donc sur tracksEggs() et l'espèce).
        $activeBatches = Batch::active()
            ->with(['species', 'productionType'])
            ->get()
            ->filter(fn (Batch $batch) => $batch->isVolaille() && $batch->tracksEggs())
            ->sortBy('code')
            ->values();

        // Durées d'incubation par espèce (en jours), pour pré-remplissage du formulaire
        $incubationDurations = [
            'poulet'  => 21,
            'pintade' => 28,
            'dinde'   => 28,
            'canard'  => 28,
            'caille'  => 17,
            'pigeon'  => 18,
        ];

        // KPI 30 jours
        $statsData = Incubation::where('updated_at', '>=', now()->subDays(30))
            ->whereIn('status', ['mirage_fait', 'clos'])    // ← 'clos' pas 'termine'
            ->get();

        $machineStats = $incubators->map(function($incubator) {
            $done = $incubator->incubations->where('status', 'clos');
            return [
                'name'         => $incubator->name,
                'total_chicks' => $done->sum('hatched_chicks'),
                'avg_hatch'    => round($done->avg('hatchability_rate') ?? 0, 1),
                'count_cycles' => $done->count()
            ];
        });

        // Historique — incubations closes avec dispatches chargés
        $historique = Incubation::where('status', 'clos')
            ->with(['batch', 'incubator'])
            ->withSum('chickDispatches', 'quantity')   // ← Pour le bouton dispatch dans l'historique
            ->latest()->get()
            ->groupBy(fn($val) => Carbon::parse($val->start_date)->translatedFormat('F Y'))
            ->map(fn($items) => [
                'items'            => $items,
                'avg_hatchability' => $items->avg('hatchability_rate'),
                'total_chicks'     => $items->sum('hatched_chicks'),
            ]);

        $stats = [
            'total_poussins'      => $statsData->where('status', 'clos')->sum('hatched_chicks'),
            'avg_fertility'       => round($statsData->avg(fn($i) => $i->fertility_rate) ?? 0, 1),
            'avg_reussite'        => round($statsData->where('status', 'clos')->avg(fn($i) => $i->hatchability_rate) ?? 0, 1),
            'machine_performance' => $machineStats,
            'historique'          => $historique,
        ];

        return view('incubations.index', compact('incubations', 'activeBatches', 'stats', 'incubators', 'sort', 'machineStats', 'providers', 'incubationDurations'));
    }

    public function store(StartIncubationRequest $request, StartIncubation $action)
    {
        $incubation = $action->execute($request->validated());
        return back()->with('success', 'Mise en couveuse enregistrée. Éclosion prévue le ' . $incubation->hatch_date_expected->format('d/m/Y'));
        
    }

    public function recordMirage(RecordMirageRequest $request, Incubation $incubation, RecordMirage $action)
    {
        $updatedIncubation = $action->execute($incubation, $request->validated());
        return back()->with('success', "Mirage enregistré : {$updatedIncubation->fertility_rate}% de fertilité.");
    }

    public function recordHatch(RecordHatchRequest $request, Incubation $incubation, RecordHatching $action)
    {
        $updatedIncubation = $action->execute($incubation, $request->validated());

        // Initialiser les compteurs (colonnes optionnelles)
        $update = [];
        if (\Schema::hasColumn('incubations', 'chicks_remaining')) {
            $update['chicks_remaining'] = $updatedIncubation->hatched_chicks;
        }
        if (\Schema::hasColumn('incubations', 'chicks_dispatched')) {
            $update['chicks_dispatched'] = 0;
        }
        if (!empty($update)) {
            $updatedIncubation->update($update);
        }

        return redirect()->route('chick-dispatches.show', $updatedIncubation)
            ->with('success', "Éclosion validée : {$updatedIncubation->hatched_chicks} poussins à dispatcher.");
    }
    public function destroy(Incubation $incubation, AbortIncubation $action)
    {
        if (Gate::denies('production.S')) return back()->with('error', 'Suppression réservée à l\'administrateur.');
        $action->execute($incubation);
        return back()->with('success', 'Cycle supprimé et annulé avec succès.');
    }
}