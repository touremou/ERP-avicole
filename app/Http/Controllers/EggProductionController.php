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
        $activeBatches = Batch::active()
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

        $stockItems = Stock::where('category', Stock::CAT_OEUFS)->get();

        $stockVendable = [];
        foreach (EggProduction::gradeCodes() as $grade) {
            $item = $stockItems->where('item_name', $grade)->first();
            $stockVendable[strtolower($grade)] = (float) ($item?->current_quantity ?? 0);
        }

        // O-01 corrigé : seulement les lots ACTIFS, pas toute l'historique
        $stockNonTrie = EggProduction::where('is_graded', false)
            ->whereHas('batch', function($q) {
                $q->active()->where('initial_quantity', '>', 0);
            })
            ->sum('total_eggs_collected');

        // 2. CORRECTION DU BUG : Calcul de la collecte brute du jour
        $totalEggsToday = EggProduction::whereDate('production_date', today())
            ->sum('total_eggs_collected');

        $recentProds      = EggProduction::with('batch')->latest()->take(15)->get();
        // On va chercher les sorties réelles dans le Magasin (StockMovement)
        $recentMovements = \App\Models\StockMovement::with('stock')
            ->whereHas('stock', fn($q) => $q->where('category', Stock::CAT_OEUFS))
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

        // Garde-fou zootechnique : pas de collecte avant l'âge d'entrée en ponte.
        $minAge = $batch->minLayingAgeDays();
        if ($batch->age < $minAge) {
            $minWeeks = (int) ceil($minAge / 7);
            return back()->with('error',
                "Lot {$batch->code} trop jeune pour la ponte : {$batch->age} jours, "
                . "phase « {$batch->current_phase} ». Entrée en ponte attendue vers ~{$minWeeks} semaines."
            );
        }

        // Biosécurité : quarantaine active → pas de formulaire de collecte
        // (l'invariant serveur vit dans RecordEggCollection ; ici, UX claire).
        if ($quarantine = $batch->activeQuarantine()) {
            return back()->with('error',
                "Lot {$batch->code} en QUARANTAINE sanitaire — collecte suspendue "
                . "jusqu'à la levée (incident n°{$quarantine->id})."
            );
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
    // FEUILLE DE TOURNÉE (collecte multi-lots)
    // ─────────────────────────────────────────────

    /**
     * Feuille de tournée du matin : une ligne par bande pondeuse active en âge
     * de ponte — saisie alvéoles + unités par ligne, un seul enregistrement.
     * (Audit UX 2026-07-03 : supprime la navigation lot par lot.)
     */
    public function tour(): View|RedirectResponse
    {
        if (Gate::denies('production.C')) {
            return back()->with('error', 'Privilèges insuffisants.');
        }

        $today     = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $batches = Batch::active()
            ->where('initial_quantity', '>', 0)
            ->with(['building', 'productionType'])
            ->withExists(['healthIncidents as is_under_quarantine' => fn ($q) => $q
                ->where('is_quarantined', true)
                ->where('status', '!=', \App\Models\HealthIncident::STATUS_RESOLVED)])
            ->get()
            ->filter(fn (Batch $b) => $b->tracksEggs() && $b->age >= $b->minLayingAgeDays())
            ->sortBy(fn (Batch $b) => $b->building->name ?? '')
            ->values();

        // Collectes du jour et de la veille en DEUX requêtes (pas de N+1).
        $todayRows = EggProduction::whereIn('batch_id', $batches->pluck('id'))
            ->where('production_date', $today)->get()->keyBy('batch_id');
        $yesterdayRows = EggProduction::whereIn('batch_id', $batches->pluck('id'))
            ->where('production_date', $yesterday)->get()->keyBy('batch_id');

        $lines = $batches->map(function (Batch $batch) use ($todayRows, $yesterdayRows) {
            $norm = \App\Models\ProductionNorm::where('batch_type', $batch->type)
                ->where('model_name', $batch->model_name)
                ->where('week_number', (int) ceil($batch->age / 7))
                ->first();

            return [
                'batch'          => $batch,
                'existing'       => $todayRows->get($batch->id),
                'yesterday_rate' => $yesterdayRows->get($batch->id)?->laying_rate,
                'target_rate'    => (float) ($norm->target_laying_rate ?? 0),
                'quarantined'    => (bool) $batch->is_under_quarantine,
            ];
        });

        return view('egg-productions.tour', compact('lines'));
    }

    /**
     * Enregistre la tournée : chaque ligne saisie passe par RecordEggCollection
     * (cumul du jour, gardes âge/quarantaine/100 % — mêmes invariants que la
     * saisie unitaire). Les lignes refusées n'annulent pas les lignes valides :
     * l'agent corrige uniquement celles en erreur.
     */
    public function tourStore(Request $request, RecordEggCollection $action): RedirectResponse
    {
        if (Gate::denies('production.C')) {
            return back()->with('error', 'Privilèges insuffisants.');
        }

        $perTray = (int) setting('general.eggs_per_tray', 30) ?: 30;

        $validated = $request->validate([
            'lines'            => 'required|array|min:1',
            'lines.*.batch_id' => 'required|integer|exists:batches,id',
            'lines.*.trays'    => 'nullable|integer|min:0',
            'lines.*.units'    => 'nullable|integer|min:0|max:' . ($perTray - 1),
        ]);

        $saved  = 0;
        $errors = [];

        foreach ($validated['lines'] as $line) {
            $total = ((int) ($line['trays'] ?? 0)) * $perTray + (int) ($line['units'] ?? 0);
            if ($total <= 0) {
                continue; // ligne non saisie : pas une erreur
            }

            try {
                $action->execute([
                    'batch_id'             => $line['batch_id'],
                    'production_date'      => now()->toDateString(),
                    'total_eggs_collected' => $total,
                    'broken_eggs'          => 0,
                    'small_eggs'           => 0,
                ]);
                $saved++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $code     = Batch::find($line['batch_id'])?->code ?? "#{$line['batch_id']}";
                $errors[] = "Lot {$code} : " . collect($e->errors())->flatten()->first();
            }
        }

        $redirect = redirect()->route('egg-productions.tour');
        if ($saved > 0) {
            $redirect->with('success', "{$saved} collecte(s) enregistrée(s).");
        }
        if ($errors !== []) {
            $redirect->with('error', implode(' | ', $errors));
        }
        if ($saved === 0 && $errors === []) {
            $redirect->with('error', 'Aucune quantité saisie — rien à enregistrer.');
        }

        return $redirect;
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
            foreach (array_map('strtolower', EggProduction::gradeCodes()) as $g) {
                $qty = (float) $eggProduction->{"grade_{$g}"};
                if ($qty > 0) {
                    $currentStock = \App\Models\Stock::where('item_name', strtoupper($g))
                                        ->where('category', Stock::CAT_OEUFS)
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
                    $qtyAlv = \App\Services\UnitConverter::eggsToTrays((float) $eggProduction->$field);
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

        $stocks = Stock::where('category', Stock::CAT_OEUFS)->orderBy('item_name')->get();
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
                $stock = Stock::where('category', Stock::CAT_OEUFS)->findOrFail($id);
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
