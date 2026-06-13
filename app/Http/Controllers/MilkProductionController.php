<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\MilkProduction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Collecte de lait (laiterie caprine — Fouta Djallon).
 *
 * Rattaché au module Production (production.L/C/M/S), au même titre que la
 * collecte d'œufs : la « production » devient multi-sortie selon l'espèce.
 */
class MilkProductionController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('production.L')) {
            return redirect()->route('dashboard')->with('error', 'Accès non autorisé.');
        }

        $today = now()->toDateString();

        // Lots laitiers actifs (pilotés par tracksMilk()).
        $dairyBatches = Batch::active()
            ->where('current_quantity', '>', 0)
            ->with(['building', 'species', 'productionType', 'milkProductions' => fn ($q) => $q->latest('production_date')->take(7)])
            ->get()
            ->filter(fn (Batch $b) => $b->tracksMilk())
            ->values();

        $totalsToday = MilkProduction::whereDate('production_date', $today)
            ->selectRaw('COALESCE(SUM(total_liters),0) as liters, COALESCE(SUM(total_liters * unit_price),0) as value')
            ->first();

        $recentProds = MilkProduction::with('batch.species')
            ->latest('production_date')->latest('id')->take(15)->get();

        // Tendance 30 jours
        $last30 = MilkProduction::where('production_date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('COALESCE(SUM(total_liters),0) as liters, COALESCE(SUM(total_liters * unit_price),0) as value')
            ->first();

        return view('milk-productions.index', compact('dairyBatches', 'totalsToday', 'recentProds', 'last30'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (Gate::denies('production.C')) {
            return back()->with('error', 'Privilèges insuffisants.');
        }

        $batchId = $request->query('batch_id');
        if (! $batchId) {
            return redirect()->route('milk-productions.index')->with('error', 'Aucun lot spécifié.');
        }

        $batch = Batch::with(['species', 'productionType'])->findOrFail($batchId);

        if (! $batch->tracksMilk()) {
            return back()->with('error', "Le lot {$batch->code} n'est pas un lot laitier.");
        }

        $today = now()->toDateString();
        $existingToday = MilkProduction::where('batch_id', $batch->id)
            ->whereDate('production_date', $today)->first();

        // Dernier prix connu pour pré-remplissage (cours volatil).
        $lastPrice = MilkProduction::where('batch_id', $batch->id)
            ->where('unit_price', '>', 0)->latest('production_date')->value('unit_price');

        return view('milk-productions.create', compact('batch', 'existingToday', 'lastPrice'));
    }

    public function store(Request $request): RedirectResponse
    {
        if (Gate::denies('production.C')) {
            return back()->with('error', 'Privilèges insuffisants.');
        }

        $data = $this->validateData($request);

        $batch = Batch::with(['species', 'productionType'])->findOrFail($data['batch_id']);
        if (! $batch->tracksMilk()) {
            return back()->with('error', "Le lot {$batch->code} n'est pas un lot laitier.");
        }

        // Une seule collecte par lot et par jour (contrainte unique) → upsert.
        MilkProduction::updateOrCreate(
            ['batch_id' => $data['batch_id'], 'production_date' => $data['production_date']],
            array_merge($data, ['recorded_by' => auth()->id()])
        );

        return redirect()->route('milk-productions.index')
            ->with('success', 'Collecte de lait enregistrée.');
    }

    public function edit(MilkProduction $milkProduction): View|RedirectResponse
    {
        if (Gate::denies('production.M')) {
            return back()->with('error', 'Modification non autorisée.');
        }

        $milkProduction->load('batch.species');
        return view('milk-productions.edit', ['milk' => $milkProduction, 'batch' => $milkProduction->batch]);
    }

    public function update(Request $request, MilkProduction $milkProduction): RedirectResponse
    {
        if (Gate::denies('production.M')) {
            return back()->with('error', 'Modification non autorisée.');
        }

        $data = $this->validateData($request, $milkProduction);
        $milkProduction->update($data);

        return redirect()->route('milk-productions.index')->with('success', 'Collecte rectifiée.');
    }

    public function destroy(MilkProduction $milkProduction): RedirectResponse
    {
        if (Gate::denies('production.S')) {
            return back()->with('error', 'Suppression réservée à la maintenance.');
        }

        $milkProduction->delete();
        return back()->with('success', 'Collecte supprimée.');
    }

    private function validateData(Request $request, ?MilkProduction $existing = null): array
    {
        return $request->validate([
            'batch_id'        => 'required|exists:batches,id',
            'production_date' => 'required|date|before_or_equal:today',
            'morning_liters'  => 'nullable|numeric|min:0',
            'evening_liters'  => 'nullable|numeric|min:0',
            'unit_price'      => 'nullable|numeric|min:0',
            'milking_females' => 'nullable|integer|min:0',
            'notes'           => 'nullable|string|max:1000',
        ]);
    }
}
