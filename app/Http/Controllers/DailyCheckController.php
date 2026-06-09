<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\DailyCheck;
use App\Models\Stock;
use App\Actions\DailyCheck\RecordDailyCheck;
use App\Http\Requests\DailyCheck\StoreDailyCheckRequest;
use App\Services\StockIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Controller des pointages journaliers.
 *
 * Bugs corrigés :
 * - S-10 : suppression du try/catch PDO (le faux mode offline)
 * - B-13 : l'Action utilise updateOrCreate avec UNIQUE index garanti (Phase 1)
 * - S-11 : lockForUpdate géré par DailyCheckObserver (Phase 2)
 */
class DailyCheckController extends Controller
{
    /**
     * Liste des pointages.
     */
    public function index(): View
    {
        if (Gate::denies('elevage.L')) {
            abort(403, 'Accès restreint.');
        }

        $dailyChecks = DailyCheck::with('batch.building')
            ->latest('check_date')
            ->paginate((int) setting('general.items_per_page', 20));

        return view('daily-checks.index', compact('dailyChecks'));
    }

    /**
     * Formulaire de création d'un pointage.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', 'Action non autorisée.');
        }
        $batchId = $request->query('batch_id');

        if (! $batchId) {
            return redirect()->route('batches.index')
                ->with('error', 'Aucun lot spécifié.');
        }

        $batch = Batch::with(['building', 'protocol.steps'])->findOrFail($batchId);

        // Préparation des phases aliment et stocks disponibles
        $rawType = strtolower($batch->type ?? 'chair');
        $isLayerSilo = in_array($rawType, ['ponte', 'repro', 'reproducteur']);

        $phases = $isLayerSilo
            ? ['Ponte Démarrage (Poussin)', 'Ponte Croissance (Poulette)', 'Ponte 1 (Pic de ponte)', 'Ponte 2 (Entretien)']
            : ['Chair Démarrage', 'Chair Croissance', 'Chair Finition'];

        $stockData = [];
        foreach ($phases as $phase) {
            // 1. Recherche par la nouvelle clé stricte
            $item = Stock::where('feed_type', $phase)
                ->where('category', 'conso')
                ->first();
                
            // 2. Conversion automatique en KG
            if ($item) {
                $stockData[$phase] = (strtolower($item->unit) === 'sac') 
                    ? (float) $item->current_quantity * 50 
                    : (float) $item->current_quantity;
            } else {
                $stockData[$phase] = 0;
            }
        }

        return view('daily-checks.create', compact('batch', 'stockData', 'phases', 'isLayerSilo'));
    }

    /**
     * Enregistrement d'un pointage.
     *
     * La logique métier (stock aliment, updateOrCreate, compensation) est dans RecordDailyCheck.
     * L'impact sur current_quantity est dans DailyCheckObserver.
     */
    public function store(StoreDailyCheckRequest $request, RecordDailyCheck $action): RedirectResponse
    {
        if (Gate::denies('elevage.C')) {
            return back()->with('error', 'Action non autorisée.');
        }
        $check = $action->execute($request->validated());

        // Save ruminant extension if applicable
        if ($check->batch->isRuminant() && ($request->has('ext_qty_born') || $request->has('ext_milk_liters'))) {
            \App\Models\DailyCheckExtension::updateOrCreate(
                ['daily_check_id' => $check->id],
                [
                    'qty_born'     => $request->integer('ext_qty_born', 0),
                    'qty_weaned'   => $request->integer('ext_qty_weaned', 0),
                    'milk_liters'  => $request->input('ext_milk_liters'),
                    'milk_fat_pct' => $request->input('ext_milk_fat_pct'),
                ]
            );
        }

        return redirect()->route('batches.show', $check->batch_id)
            ->with('success', 'Pointage enregistré et stock mis à jour.');
    }

    /**
     * Formulaire d'édition.
     */
    public function edit(DailyCheck $daily_check): View|RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Modification interdite.');
        }

        $check = $daily_check->load('batch');

        if ($check->batch->status !== 'Actif') {
            return redirect()->route('batches.show', $check->batch_id)
                ->with('error', 'Lot clôturé : modification impossible.');
        }

        // PRÉPARATION DES STOCKS POUR LA VUE (Évite les requêtes DB dans le Blade)
        $rawType = strtolower($check->batch->type ?? 'chair');
        $isLayerSilo = in_array($rawType, ['ponte', 'repro', 'reproducteur']);

        $phases = $isLayerSilo
            ? ['Ponte Démarrage (Poussin)', 'Ponte Croissance (Poulette)', 'Ponte 1 (Pic de ponte)', 'Ponte 2 (Entretien)']
            : ['Chair Démarrage', 'Chair Croissance', 'Chair Finition'];

        $stockData = [];
        foreach ($phases as $phase) {
            $item = Stock::where('feed_type', $phase) // Utilisation propre de la façade importée
                ->where('category', 'conso')
                ->first();
                
            if ($item) {
                $stockData[$phase] = (strtolower($item->unit) === 'sac') 
                    ? (float) $item->current_quantity * 50 
                    : (float) $item->current_quantity;
            } else {
                $stockData[$phase] = 0;
            }
        }

        return view('daily-checks.edit', compact('check', 'phases', 'stockData'));
    }

    /**
     * Mise à jour d'un pointage.
     *
     * Gère la compensation de stock aliment et le recalcul d'impact sur le lot.
     * Le DailyCheckObserver gère la mise à jour de current_quantity.
     */
    public function update(Request $request, DailyCheck $daily_check): RedirectResponse
    {
        if (Gate::denies('elevage.M')) {
            return back()->with('error', 'Modification interdite.');
        }

        $check = $daily_check;
        $batch = $check->batch;

        $validated = $request->validate([
            'mortality'          => 'required|integer|min:0',
            'feed_consumed'      => 'required|numeric|min:0',
            'feed_type'          => 'required|string',
            'water_consumed'     => 'nullable|numeric|min:0',
            'temp_min'           => 'nullable|numeric',
            'temp_max'           => 'nullable|numeric',
            'humidity'           => 'nullable|numeric|min:0|max:100',
            'avg_weight'         => 'nullable|numeric|min:0',
            'qty_quarantine_in'  => 'required|integer|min:0',
            'qty_quarantine_out' => 'required|integer|min:0',
            'qty_sorted_out'     => 'nullable|integer|min:0',
            'treatment_type'     => 'nullable|string|max:255',
            'treatment_name'     => 'nullable|string|max:255',
            'observations'       => 'nullable|string',
        ]);

        // Vérification effectif
        $oldImpact = $check->calculateNetImpact();
        $newImpact = ((int) $validated['mortality'] + (int) $validated['qty_quarantine_in'] + (int) ($validated['qty_sorted_out'] ?? 0))
                   - (int) $validated['qty_quarantine_out'];
        $diff = $newImpact - $oldImpact;

        if (($batch->current_quantity - $diff) < 0) {
            return back()->withErrors(['mortality' => "L'effectif du lot deviendrait négatif."])->withInput();
        }

        // Vérification stock aliment
        $availableKg = $this->getAvailableStockInKg($validated['feed_type']);
        if (trim($check->feed_type) === trim($validated['feed_type'])) {
            $availableKg += (float) $check->feed_consumed;
        }
        if ($validated['feed_consumed'] > $availableKg) {
            return back()->withErrors([
                'feed_consumed' => "Stock insuffisant pour {$validated['feed_type']}. Disponible : " . number_format($availableKg, 1) . " kg",
            ])->withInput();
        }

        return DB::transaction(function () use ($request, $check, $validated) {
            // Restitution de l'ancien stock
            if ((float) $check->feed_consumed > 0) {
                StockIntegrationService::syncMovement(
                    $check->feed_type, 'conso', (float) $check->feed_consumed, 'in',
                    "Rectification pointage #{$check->id} (annulation)", 'KG'
                );
            }

            // Nouveau mouvement de sortie
            if ((float) $validated['feed_consumed'] > 0) {
                StockIntegrationService::syncMovement(
                    $validated['feed_type'], 'conso', (float) $validated['feed_consumed'], 'out',
                    "Rectification pointage #{$check->id} (nouvelle conso)", 'KG'
                );
            }

            $validated['litter_changed'] = $request->has('litter_changed') ? 1 : 0;
            $validated['qty_sorted_out'] = $validated['qty_sorted_out'] ?? 0;

            // L'observer DailyCheckObserver gère le diff sur current_quantity
            $check->update($validated);

            return redirect()->route('batches.show', $check->batch_id)
                ->with('success', 'Pointage et stocks rectifiés.');
        });
    }

    /**
     * Suppression d'un pointage.
     */
    public function destroy(DailyCheck $daily_check): RedirectResponse
    {
        if (Gate::denies('elevage.S')) {
            return back()->with('error', 'Suppression réservée aux administrateurs.');
        }

        $check = $daily_check;
        $batchId = $check->batch_id;

        return DB::transaction(function () use ($check, $batchId) {
            // Restitution du stock aliment
            if ((float) $check->feed_consumed > 0) {
                StockIntegrationService::syncMovement(
                    $check->feed_type, 'conso', (float) $check->feed_consumed, 'in',
                    "Suppression pointage - Restitution stock", 'KG'
                );
            }

            // L'observer DailyCheckObserver gère la restitution de current_quantity
            $check->delete();

            return redirect()->route('batches.show', $batchId)
                ->with('success', 'Pointage supprimé et stocks restitués.');
        });
    }

    /**
     * Helper : stock disponible en KG pour un type d'aliment.
     */
    private function getAvailableStockInKg(string $feedType): float
    {
        // 1. Recherche stricte
        $stock = Stock::where('feed_type', trim($feedType))
            ->where('category', 'conso')
            ->first();

        if (!$stock) {
            return 0;
        }

        // 2. Conversion en KG
        return (strtolower($stock->unit) === 'sac') 
            ? (float) $stock->current_quantity * 50 
            : (float) $stock->current_quantity;
    }
}
