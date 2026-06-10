<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\EggMovement;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Actions\EggProduction\RecordEggCollection;
use App\Actions\EggProduction\GradeEggProduction;
use App\Http\Requests\EggProduction\StoreEggProductionRequest;
use App\Http\Requests\EggProduction\UpdateTriRequest;
use App\Services\StockIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Controller des collectes d'œufs.
 *
 * Corrections :
 * - O-01 : stockNonTrie filtre sur lots actifs uniquement
 * - O-02 : storeMovement() supprimé — tout passe par EggMovementController
 * - O-03 : update() bloqué si is_graded = true
 * - O-04 : create() lit batch_id depuis query string (pas paramètre de route)
 * - O-05 : uniformisation sur syncMovement() via GradeEggProduction Action
 * - O-06 : rebase() avec validation stricte des inputs
 * - O-08 : laying_rate calculé sur current_quantity (effectif vivant réel)
 * - O-09 : destroy() vérifie les mouvements liés avant suppression
 * - OQ-01/02/03 : Form Requests + Actions + vue edit dédiée
 */
class EggProductionController extends Controller
{
    // ─────────────────────────────────────────────
    // DASHBOARD
    // ─────────────────────────────────────────────

    public function index(): View|RedirectResponse
    {
        if (Gate::denies('production.L')) return redirect()->route('dashboard')->with('error', 'Accès non autorisé.');

        $today = now()->toDateString();

        // Lots pondeurs : pilotés par le type de production de l'espèce
        // (avec repli sur l'ancien typage volaille pour les lots sans species_id).
        $activeBatches = Batch::where('status', 'Actif')
            ->where('initial_quantity', '>', 0)
            ->with(['building', 'productionType', 'eggProductions' => fn($q) => $q->latest()->take(7)])
            ->get()
            ->filter(fn (Batch $batch) => $batch->tracksEggs())
            ->values();

        $totalsToday = EggProduction::whereDate('production_date', $today)
            ->selectRaw('
                COALESCE(SUM(total_eggs_collected), 0) as totalEggs,
                COALESCE(SUM(broken_eggs), 0)          as totalBroken,
                COALESCE(SUM(small_eggs), 0)           as totalSmall
            ')
            ->first();

        $stockItems = Stock::where('category', 'oeufs')->get();

        $stockVendable = [];
        foreach (['XL', 'L', 'M', 'S'] as $grade) {
            $item = $stockItems->where('item_name', $grade)->first();
            $stockVendable[strtolower($grade)] = (float) ($item?->current_quantity ?? 0);
        }

        // O-01 corrigé : seulement les lots ACTIFS, pas toute l'historique
        $stockNonTrie = EggProduction::where('is_graded', false)
            ->whereHas('batch', function($q) {
                $q->where('status', 'Actif')->where('initial_quantity', '>', 0);
            })
            ->sum('total_eggs_collected');

        // 2. CORRECTION DU BUG : Calcul de la collecte brute du jour
        $totalEggsToday = EggProduction::whereDate('production_date', today())
            ->sum('total_eggs_collected');

        $recentProds      = EggProduction::with('batch')->latest()->take(15)->get();
        // On va chercher les sorties réelles dans le Magasin (StockMovement)
        $recentMovements = \App\Models\StockMovement::with('stock')
            ->whereHas('stock', fn($q) => $q->where('category', 'oeufs'))
            ->where('type', 'out') // On ne prend que les sorties
            ->latest()
            ->take(10)
            ->get();

        return view('egg-productions.index', compact('totalEggsToday',
            'activeBatches', 'stockVendable', 'stockItems',
            'stockNonTrie', 'recentProds', 'recentMovements',
            'totalsToday'
        ));
    }

    // ─────────────────────────────────────────────
    // COLLECTE BRUTE
    // ─────────────────────────────────────────────

    /**
     * O-04 corrigé : batch_id lu depuis la query string.
     * Route : GET /egg-productions/create?batch_id=3
     */
    public function create(Request $request): View|RedirectResponse
    {
        if (Gate::denies('production.C')) {
            return back()->with('error', 'Privilèges insuffisants.');
        }

        $batchId = $request->query('batch_id');

        if (! $batchId) {
            return redirect()->route('batches.index')
                ->with('error', 'Aucun lot spécifié.');
        }

        $batch = Batch::with('productionType')->findOrFail($batchId);

        if (! $batch->tracksEggs()) {
            return back()->with('error', "Le lot {$batch->code} n'est pas un lot de ponte.");
        }

        $today = now()->toDateString();
        $existingToday = EggProduction::where('batch_id', $batch->id)
            ->where('production_date', $today)
            ->first();

        return view('egg-productions.create', compact('batch', 'existingToday'));
    }

    /**
     * Enregistrement d'une collecte brute.
     * O-08 corrigé : laying_rate sur current_quantity via RecordEggCollection Action.
     */
    public function store(StoreEggProductionRequest $request, RecordEggCollection $action): RedirectResponse
    {
        $action->execute($request->validated());

        return redirect()->route('egg-productions.index')
            ->with('success', 'Collecte brute enregistrée.');
    }

    // ─────────────────────────────────────────────
    // RECTIFICATION (collecte non encore triée)
    // ─────────────────────────────────────────────

    public function edit(EggProduction $eggProduction): View|RedirectResponse
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Modification non autorisée.');

        // O-03 corrigé : bloquer si déjà trié
        if ($eggProduction->is_graded) {
            return back()->with('error',
                "Cette collecte a déjà été triée. Pour la modifier, refaites le tri via 'Saisir le tri'."
            );
        }

        return view('egg-productions.edit', [
            'eggProduction' => $eggProduction,
            'batch'         => $eggProduction->batch,
        ]);
    }

    /**
     * O-03 corrigé : modification bloquée si is_graded = true.
     */
    public function update(Request $request, EggProduction $eggProduction): RedirectResponse
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Modification non autorisée.');

        if ($eggProduction->is_graded) {
            return back()->with('error',
                "Collecte déjà triée. Impossible de modifier le total sans refaire le tri."
            );
        }

        $validated = $request->validate([
            'total_eggs_collected' => 'required|integer|min:0',
            'broken_eggs'          => 'nullable|integer|min:0',
            'small_eggs'           => 'nullable|integer|min:0',
            'observations'         => 'nullable|string|max:500',
        ]);

        $batch = $eggProduction->batch;
        $layingRate = $batch->current_quantity > 0
            ? round(($validated['total_eggs_collected'] / $batch->current_quantity) * 100, 2)
            : 0;

        $eggProduction->update(array_merge($validated, ['laying_rate' => $layingRate]));

        return redirect()->route('egg-productions.index')
            ->with('success', 'Collecte rectifiée.');
    }

    // ─────────────────────────────────────────────
    // TRI PAR CALIBRE
    // ─────────────────────────────────────────────

    public function tri(EggProduction $eggProduction): View|RedirectResponse
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Action non autorisée.');

        return view('egg-productions.tri', [
            'eggProduction' => $eggProduction,
            'batch'         => $eggProduction->batch,
        ]);
    }

    /**
     * O-05 corrigé : syncMovement() uniforme via GradeEggProduction Action.
     */
    public function updateTri(UpdateTriRequest $request, EggProduction $eggProduction, GradeEggProduction $action): RedirectResponse
    {
        $action->execute($eggProduction, $request->validated());

        return redirect()->route('egg-productions.index')
            ->with('success', 'Tri et stocks synchronisés.');
    }

    // ─────────────────────────────────────────────
    // SUPPRESSION
    // ─────────────────────────────────────────────

    /**
     * O-09 corrigé : vérifie les mouvements liés avant suppression.
     */
    public function destroy(EggProduction $eggProduction): RedirectResponse
    {
        if (Gate::denies('production.S')) return back()->with('error', 'Suppression réservée à la maintenance.');

        // SÉCURITÉ ERP : Si déjà trié, on vérifie que le stock global est suffisant pour absorber l'annulation
        if ($eggProduction->is_graded) {
            foreach (['xl', 'l', 'm', 's'] as $g) {
                $qty = (float) $eggProduction->{"grade_{$g}"};
                if ($qty > 0) {
                    $currentStock = \App\Models\Stock::where('item_name', strtoupper($g))
                                        ->where('category', 'oeufs')
                                        ->value('current_quantity') ?? 0;
                    
                    if ($currentStock < $qty) {
                        return back()->with('error', 
                            "Impossible d'annuler cette collecte. Le stock actuel de calibre " . strtoupper($g) . 
                            " (" . number_format($currentStock, 1) . ") est inférieur à ce qui a été trié (" . number_format($qty, 1) . "). Des œufs ont déjà été expédiés."
                        );
                    }
                }
            }
        }

        return DB::transaction(function () use ($eggProduction) {
            // Restitution des stocks
            if ($eggProduction->is_graded) {
                foreach (['xl', 'l', 'm', 's'] as $g) {
                    $qty = (float) $eggProduction->{"grade_{$g}"};
                    if ($qty > 0) {
                        StockIntegrationService::syncMovement(
                            strtoupper($g), 'oeufs', $qty, 'out', // "out" pour annuler une entrée
                            "ANNULATION collecte #{$eggProduction->id} lot {$eggProduction->batch->code}",
                            'Alvéole'
                        );
                    }
                }
                foreach (['broken_eggs' => 'Cassé', 'small_eggs' => 'Anomalie'] as $field => $name) {
                    $qtyAlv = (float) $eggProduction->$field / 30;
                    if ($qtyAlv > 0) {
                        StockIntegrationService::syncMovement(
                            $name, 'oeufs', $qtyAlv, 'out',
                            "ANNULATION pertes #{$eggProduction->id}",
                            'Alvéole'
                        );
                    }
                }
            }

            $eggProduction->delete();
            return back()->with('success', 'Collecte annulée avec succès et stocks ajustés.');
        });
    }

    // ─────────────────────────────────────────────
    // MAINTENANCE — INVENTAIRE PHYSIQUE
    // ─────────────────────────────────────────────

    public function maintenance(): View|RedirectResponse
    {
        if (Gate::denies('production.S')) return back()->with('error', 'Accès maintenance refusé.');

        $stocks = Stock::where('category', 'oeufs')->orderBy('item_name')->get();
        return view('egg-productions.maintenance', compact('stocks'));
    }

    /**
     * O-06 corrigé : validation stricte des inputs + audit trail complet.
     */
    public function rebase(Request $request): RedirectResponse
    {
        if (Gate::denies('production.S')) return back()->with('error', 'Accès maintenance refusé.');

        $request->validate([
            'stocks'         => 'required|array|min:1',
            'stocks.*'       => 'required|numeric|min:0',
        ]);

        $updated = 0;

        DB::transaction(function () use ($request, &$updated) {
            foreach ($request->input('stocks') as $id => $newValue) {
                $stock = Stock::where('category', 'oeufs')->findOrFail($id);
                $oldValue = (float) $stock->current_quantity;
                $newValue = (float) $newValue;

                if (abs($oldValue - $newValue) < 0.001) continue;

                $stock->update(['current_quantity' => $newValue]);

                StockMovement::create([
                    'stock_id' => $stock->id,
                    'user_id'  => auth()->id(),
                    'type'     => 'adjustment',
                    'quantity' => abs($newValue - $oldValue),
                    'notes'    => "Inventaire physique : {$stock->item_name} "
                               . number_format($oldValue, 2) . " → "
                               . number_format($newValue, 2) . " alvéoles",
                ]);

                $updated++;
            }
        });

        return redirect()->route('egg-productions.index')
            ->with('success', "{$updated} article(s) synchronisés depuis l'inventaire physique.");
    }
}
