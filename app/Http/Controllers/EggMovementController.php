<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\EggProduction;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\StockIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * EggMovementController — Refactoré
 *
 * Gère :
 * - Tri des œufs par calibre (S, M, L, XL) après collecte
 * - Synchronisation automatique avec le stock (StockIntegrationService)
 * - Mouvements d'œufs (entrée stock, sortie vente, transfert, casse)
 * - Validation rigoureuse (totaux cohérents, pas de stock négatif)
 *
 */
class EggMovementController extends Controller
{
    /**
     * Formulaire de tri des œufs pour une collecte donnée.
     */
    public function showTriForm(EggProduction $eggProduction)
    {
        if (Gate::denies('production.C')) return back()->with('error', 'Action non autorisée.');

        if ($eggProduction->is_sorted) {
            return back()->with('error', "La collecte du {$eggProduction->collection_date->format('d/m/Y')} est déjà triée.");
        }

        $eggProduction->load('batch.building');

        // Stock actuel par calibre
        $calibreStocks = Stock::where('category', 'oeufs')
            ->whereIn('item_name', ['S', 'M', 'L', 'XL'])
            ->get()
            ->keyBy('item_name');

        return view('egg-movements.tri', compact('eggProduction', 'calibreStocks'));
    }

    /**
     * Enregistre le tri par calibre et synchronise le stock.
     *
     * Validation :
     * - La somme des calibres + cassés + anomalies DOIT = total_collected
     * - Chaque calibre >= 0
     * - Le tri ne peut être fait qu'une fois par collecte
     */
    public function storeTri(Request $request, EggProduction $eggProduction)
    {
        if (Gate::denies('production.C')) return back()->with('error', 'Action non autorisée.');

        if ($eggProduction->is_sorted) {
            return back()->with('error', 'Cette collecte est déjà triée.');
        }

        $validated = $request->validate([
            'qty_s'       => 'required|integer|min:0',
            'qty_m'       => 'required|integer|min:0',
            'qty_l'       => 'required|integer|min:0',
            'qty_xl'      => 'required|integer|min:0',
            'qty_broken'  => 'required|integer|min:0',
            'qty_anomaly' => 'required|integer|min:0',
            'notes'       => 'nullable|string|max:500',
        ]);

        // Validation métier : total tri = total collecté
        $totalTri = $validated['qty_s'] + $validated['qty_m'] + $validated['qty_l']
            + $validated['qty_xl'] + $validated['qty_broken'] + $validated['qty_anomaly'];

        $totalCollected = (int) $eggProduction->total_collected;

        if ($totalTri !== $totalCollected) {
            return back()->withErrors([
                'qty_s' => "Le total du tri ({$totalTri}) ne correspond pas au total collecté ({$totalCollected}). Différence : " . abs($totalTri - $totalCollected) . " œufs."
            ])->withInput();
        }

        return DB::transaction(function () use ($eggProduction, $validated) {

            // 1. Mettre à jour la collecte
            $eggProduction->update([
                'qty_s'       => $validated['qty_s'],
                'qty_m'       => $validated['qty_m'],
                'qty_l'       => $validated['qty_l'],
                'qty_xl'      => $validated['qty_xl'],
                'qty_broken'  => $validated['qty_broken'],
                'qty_anomaly' => $validated['qty_anomaly'],
                'is_sorted'   => true,
                'sorted_by'   => Auth::id(),
                'sorted_at'   => now(),
                'notes'       => $validated['notes'] ?? $eggProduction->notes,
            ]);

            // 2. Synchroniser le stock par calibre (en Unités → conversion en Alvéoles par StockIntegrationService)
            $calibres = [
                'S'  => $validated['qty_s'],
                'M'  => $validated['qty_m'],
                'L'  => $validated['qty_l'],
                'XL' => $validated['qty_xl'],
            ];

            $syncResults = [];
            foreach ($calibres as $calibre => $qty) {
                if ($qty > 0) {
                    $result = StockIntegrationService::syncMovement(
                        $calibre,
                        'oeufs',
                        $qty,
                        'in',
                        "Tri collecte #{$eggProduction->id} — Lot {$eggProduction->batch->code} — {$eggProduction->collection_date->format('d/m/Y')}",
                        'Unité' // En unités d'œufs, le service convertit en alvéoles (÷30)
                    );

                    $syncResults[$calibre] = $result ? 'OK' : 'ÉCHEC';

                    if (! $result) {
                        Log::warning("EggMovementController: échec sync stock calibre {$calibre} — article peut-être inexistant dans stocks.");
                    }
                }
            }

            Log::info("Tri œufs enregistré — Collecte #{$eggProduction->id}, Lot {$eggProduction->batch->code}: S={$validated['qty_s']}, M={$validated['qty_m']}, L={$validated['qty_l']}, XL={$validated['qty_xl']}, Cassés={$validated['qty_broken']}, Anomalies={$validated['qty_anomaly']}");

            // Vérifier si des syncs ont échoué
            $failures = collect($syncResults)->filter(fn($r) => $r === 'ÉCHEC');
            if ($failures->isNotEmpty()) {
                $failedCal = $failures->keys()->implode(', ');
                return back()->with('warning',
                    "Tri enregistré, mais le stock n'a pas été mis à jour pour : {$failedCal}. " .
                    "Vérifiez que les articles S, M, L, XL existent dans le stock catégorie 'oeufs'."
                );
            }

            return redirect()->route('egg-productions.show', $eggProduction)
                ->with('success', "Tri enregistré et stock mis à jour. S:{$validated['qty_s']} M:{$validated['qty_m']} L:{$validated['qty_l']} XL:{$validated['qty_xl']}");
        });
    }

    /**
     * Corrige un tri existant (manager+ requis).
     *
     * Annule le stock de l'ancien tri et applique le nouveau.
     */
    public function updateTri(Request $request, EggProduction $eggProduction)
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Correction réservée aux managers.');

        if (! $eggProduction->is_sorted) {
            return back()->with('error', 'Cette collecte n\'a pas encore été triée.');
        }

        $validated = $request->validate([
            'qty_s'       => 'required|integer|min:0',
            'qty_m'       => 'required|integer|min:0',
            'qty_l'       => 'required|integer|min:0',
            'qty_xl'      => 'required|integer|min:0',
            'qty_broken'  => 'required|integer|min:0',
            'qty_anomaly' => 'required|integer|min:0',
            'correction_reason' => 'required|string|max:500',
        ]);

        $totalTri = $validated['qty_s'] + $validated['qty_m'] + $validated['qty_l']
            + $validated['qty_xl'] + $validated['qty_broken'] + $validated['qty_anomaly'];

        if ($totalTri !== (int) $eggProduction->total_collected) {
            return back()->withErrors([
                'qty_s' => "Total tri ({$totalTri}) ≠ total collecté ({$eggProduction->total_collected})."
            ])->withInput();
        }

        return DB::transaction(function () use ($eggProduction, $validated) {

            // 1. Annuler l'ancien tri (sortie stock des anciens calibres)
            $oldCalibres = [
                'S'  => (int) $eggProduction->qty_s,
                'M'  => (int) $eggProduction->qty_m,
                'L'  => (int) $eggProduction->qty_l,
                'XL' => (int) $eggProduction->qty_xl,
            ];

            foreach ($oldCalibres as $calibre => $qty) {
                if ($qty > 0) {
                    StockIntegrationService::syncMovement(
                        $calibre, 'oeufs', $qty, 'out',
                        "CORRECTION tri #{$eggProduction->id} — annulation ancien tri",
                        'Unité'
                    );
                }
            }

            // 2. Appliquer le nouveau tri (entrée stock)
            $newCalibres = [
                'S'  => $validated['qty_s'],
                'M'  => $validated['qty_m'],
                'L'  => $validated['qty_l'],
                'XL' => $validated['qty_xl'],
            ];

            foreach ($newCalibres as $calibre => $qty) {
                if ($qty > 0) {
                    StockIntegrationService::syncMovement(
                        $calibre, 'oeufs', $qty, 'in',
                        "CORRECTION tri #{$eggProduction->id} — nouveau tri — {$validated['correction_reason']}",
                        'Unité'
                    );
                }
            }

            // 3. Mettre à jour la collecte
            $eggProduction->update([
                'qty_s'       => $validated['qty_s'],
                'qty_m'       => $validated['qty_m'],
                'qty_l'       => $validated['qty_l'],
                'qty_xl'      => $validated['qty_xl'],
                'qty_broken'  => $validated['qty_broken'],
                'qty_anomaly' => $validated['qty_anomaly'],
                'notes'       => trim(($eggProduction->notes ?? '') . "\n[CORRECTION " . now()->format('d/m/Y H:i') . " par " . Auth::user()->name . "] " . $validated['correction_reason']),
            ]);

            Log::info("Correction tri œufs — Collecte #{$eggProduction->id} par " . Auth::user()->name . ": {$validated['correction_reason']}");

            return back()->with('success', 'Tri corrigé et stock recalculé.');
        });
    }

    /**
     * Mouvement manuel d'œufs (transfert entre catégories, ajustement inventaire).
     */
    public function storeMovement(Request $request)
    {
        if (Gate::denies('production.M')) return back()->with('error', 'Action réservée aux managers.');

        $validated = $request->validate([
            'calibre'   => 'required|in:' . implode(',', \App\Models\EggProduction::gradeCodes()),
            'type'      => 'required|in:in,out,adjustment',
            'quantity'  => 'required|integer|min:1',
            'unit'      => 'required|in:unite,alveole',
            'reason'    => 'required|string|max:500',
        ]);

        $inputUnit = $validated['unit'] === 'alveole' ? 'Alvéole' : 'Unité';

        $result = StockIntegrationService::syncMovement(
            $validated['calibre'],
            'oeufs',
            (int) $validated['quantity'],
            $validated['type'],
            $validated['reason'] . ' (mouvement manuel par ' . Auth::user()->name . ')',
            $inputUnit
        );

        if (! $result) {
            return back()->with('error', "Mouvement impossible : article '{$validated['calibre']}' introuvable dans le stock œufs.");
        }

        return back()->with('success',
            "Mouvement {$validated['type']} de {$validated['quantity']} {$inputUnit}(s) — Calibre {$validated['calibre']} enregistré."
        );
    }
}
