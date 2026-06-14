<?php

namespace App\Http\Controllers;

use App\Models\RawMaterial;
use App\Models\Provider;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RawMaterialController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.L')) return redirect()->route('dashboard')->with('error', 'Accès non autorisé.');

        $materials = RawMaterial::withCount('formulaItems')->get();
        $providers = Provider::where('status', 'Actif')->get();

        return view('provenderie.materials.index', compact('materials', 'providers'));
    }

    /**
     * Enregistrement d'un nouvel ingrédient.
     *
     * HOTFIX : La validation 'unit' accepte maintenant majuscules ET minuscules.
     * L'ancien code avait 'in:kg,L,unite' mais le formulaire envoyait 'KG'.
     */
    public function store(Request $request): RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Privilèges insuffisants.');

        // Normaliser l'unité en minuscule avant validation
        $request->merge([
            'unit' => strtolower($request->input('unit', 'kg')),
        ]);

        $validated = $request->validate([
            'name'            => 'required|string|unique:raw_materials,name|max:100',
            'unit'            => 'required|string|in:kg,l,unite',
            'stock_qty'       => 'nullable|numeric|min:0',
            'unit_cost'       => 'nullable|numeric|min:0',
            'alert_threshold' => 'nullable|numeric|min:0',
            'energy_kcal'     => 'nullable|numeric|min:0',
            'protein_rate'    => 'nullable|numeric|min:0|max:100',
            'lysine_rate'     => 'nullable|numeric|min:0|max:100',
            'calcium_rate'    => 'nullable|numeric|min:0|max:100',
        ], [
            'name.unique' => 'Une matière première porte déjà ce nom.',
        ]);

        // Valeurs par défaut pour les colonnes NOT NULL
        $validated['stock_qty']       = $validated['stock_qty'] ?? 0;
        $validated['unit_cost']       = $validated['unit_cost'] ?? 0;
        $validated['alert_threshold'] = $validated['alert_threshold'] ?? 100;
        $validated['energy_kcal']     = $validated['energy_kcal'] ?? 0;
        $validated['protein_rate']    = $validated['protein_rate'] ?? 0;
        $validated['lysine_rate']     = $validated['lysine_rate'] ?? 0;
        $validated['calcium_rate']    = $validated['calcium_rate'] ?? 0;
        $validated['is_active']       = true;

        RawMaterial::create($validated);

        return back()->with('success', "Ingrédient '{$validated['name']}' ajouté au référentiel.");
    }

    /**
     * Réception de commande avec calcul CMP.
     */
    public function updateStock(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Saisie non autorisée.');

        $material = RawMaterial::findOrFail($id);

        $request->validate([
            'added_qty'      => 'required|numeric|min:0.01',
            'purchase_price' => 'required|numeric|min:0',
            'provider_id'    => 'nullable|exists:providers,id',
        ]);

        return DB::transaction(function () use ($material, $request) {
            $addedQty  = (float) $request->added_qty;
            $totalPaid = (float) $request->purchase_price;

            // Coût Moyen Pondéré
            $currentValue = (float) $material->stock_qty * (float) $material->unit_cost;
            $totalQty     = (float) $material->stock_qty + $addedQty;
            $newUnitCost  = $totalQty > 0 ? ($currentValue + $totalPaid) / $totalQty : $material->unit_cost;

            $material->update([
                'stock_qty' => $totalQty,
                'unit_cost' => round($newUnitCost, 2),
            ]);

            if ($material->stock_id) {
                StockMovement::create([
                    'stock_id' => $material->stock_id,
                    'user_id'  => Auth::id() ?? 1,
                    'type'     => 'in',
                    'quantity' => $addedQty,
                    'notes'    => "Réception {$material->name} : +{$addedQty} {$material->unit}, CMP → " . round($newUnitCost, 2) . " GNF",
                ]);
            }

            return back()->with('success', 'Stock approvisionné et CMP recalculé.');
        });
    }

    /**
     * Mise à jour des valeurs nutritionnelles (labo).
     */
    public function updateNutrition(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Action non autorisée.');

        $request->validate([
            'energy_kcal'  => 'required|numeric|min:0',
            'protein_rate' => 'required|numeric|min:0|max:100',
            'lysine_rate'  => 'nullable|numeric|min:0|max:100',
            'calcium_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $material = RawMaterial::findOrFail($id);
        $material->update($request->only(['energy_kcal', 'protein_rate', 'lysine_rate', 'calcium_rate']));

        return back()->with('success', "Valeurs nutritionnelles de {$material->name} mises à jour.");
    }

    /**
     * Suppression avec vérifications d'intégrité.
     */
    public function destroy($id): RedirectResponse
    {
        if (Gate::denies('provenderie.S')) return back()->with('error', 'Suppression réservée à la maintenance.');

        $material = RawMaterial::findOrFail($id);

        if ($material->stock_qty > 0.01) {
            return back()->with('error', "Impossible : il reste {$material->stock_qty} {$material->unit} en stock.");
        }

        if ($material->formulaItems()->exists()) {
            return back()->with('error', "Impossible : cet ingrédient est utilisé dans des formules actives.");
        }

        $material->delete();
        return back()->with('success', 'Matière première retirée.');
    }

    /**
     * Ajustement d'inventaire (pertes, sorties).
     */
    public function removeStock(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Ajustement non autorisé.');

        $material = RawMaterial::findOrFail($id);

        $request->validate([
            'qty'    => 'required|numeric|min:0.01|max:' . $material->stock_qty,
            'reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($material, $request) {
            $material->decrement('stock_qty', $request->qty);

            if ($material->stock_id) {
                StockMovement::create([
                    'stock_id' => $material->stock_id,
                    'user_id'  => Auth::id() ?? 1,
                    'type'     => 'out',
                    'quantity' => $request->qty,
                    'notes'    => "Ajustement {$material->name} -{$request->qty} {$material->unit}. Motif: {$request->reason}",
                ]);
            }

            return back()->with('success', "Dépréciation de {$request->qty} {$material->unit} enregistrée.");
        });
    }
}
