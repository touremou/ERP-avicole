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
    /**
     * Règles de validation des teneurs, DÉRIVÉES de la table des nutriments.
     *
     * Ces règles étaient recopiées à la main dans store(), update() et
     * updateNutrition() — et les trois listes différaient : la lysine était
     * validée à la création mais absente de tout formulaire, donc toujours nulle,
     * tandis que la fiche d'analyse affichait sa cible. En dérivant la liste du
     * référentiel, un nutriment ajouté est accepté partout du même coup.
     */
    private function nutrientRules(): array
    {
        $rules = [];

        foreach (\App\Models\FoodNorm::NUTRIENTS as $nutrient) {
            $column = $nutrient['material'];
            $max = $nutrient['unit'] === '%' ? '|max:100' : '';
            $rules[$column] = 'nullable|numeric|min:0' . $max;
        }

        return $rules;
    }

    /** Colonnes de teneur, dans l'ordre du référentiel. */
    private function nutrientColumns(): array
    {
        return array_column(\App\Models\FoodNorm::NUTRIENTS, 'material');
    }

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

        $validated = $request->validate(array_merge([
            'name'            => 'required|string|unique:raw_materials,name|max:100',
            'unit'            => 'required|string|in:kg,l,unite',
            'stock_qty'       => 'nullable|numeric|min:0',
            'unit_cost'       => 'nullable|numeric|min:0',
            'alert_threshold' => 'nullable|numeric|min:0',
        ], $this->nutrientRules()), [
            'name.unique' => 'Une matière première porte déjà ce nom.',
        ]);

        // Valeurs par défaut pour les colonnes NOT NULL. Une teneur laissée vide
        // vaut 0 = « non analysé » : l'analyse d'une formule le signalera au lieu
        // de conclure à une carence (cf. Formula::nutrientCoverage()).
        $validated['stock_qty']       = $validated['stock_qty'] ?? 0;
        $validated['unit_cost']       = $validated['unit_cost'] ?? 0;
        $validated['alert_threshold'] = $validated['alert_threshold'] ?? 100;
        $validated['is_active']       = true;

        foreach ($this->nutrientColumns() as $column) {
            $validated[$column] = $validated[$column] ?? 0;
        }

        RawMaterial::create($validated);

        return back()->with('success', "Ingrédient '{$validated['name']}' ajouté au référentiel.");
    }

    /**
     * Modification des informations générales d'un ingrédient (fiche éditable).
     */
    public function update(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Modification non autorisée.');

        $material = RawMaterial::findOrFail($id);

        $request->merge([
            'unit' => strtolower($request->input('unit', $material->unit)),
        ]);

        $validated = $request->validate(array_merge([
            'name'            => 'required|string|max:100|unique:raw_materials,name,' . $material->id,
            'unit'            => 'required|string|in:kg,l,unite',
            'stock_qty'       => 'required|numeric|min:0',
            'unit_cost'       => 'required|numeric|min:0',
            'alert_threshold' => 'nullable|numeric|min:0',
        ], $this->nutrientRules()), [
            'name.unique' => 'Une matière première porte déjà ce nom.',
        ]);

        $material->update($validated);

        return back()->with('success', "Ingrédient '{$material->name}' mis à jour.");
    }

    /**
     * ENTRÉE D'INVENTAIRE : on corrige la quantité en magasin, et la valeur
     * saisie sert UNIQUEMENT à recalculer le coût moyen pondéré.
     *
     * ─── CE QUE CE GESTE NE FAIT PAS ───
     *
     * Aucune facture fournisseur, aucun règlement, aucune écriture de trésorerie.
     * L'argent de l'achat se saisit séparément au module Dépenses. C'est une
     * convention, pas un oubli : elle est verrouillée par un test
     * (RawMaterialEntryIsInventoryOnlyTest), sans quoi elle dériverait — le
     * formulaire portait le libellé « Réception » et « Montant Facturé », qui se
     * lisent comme une réception d'achat, et le mouvement de stock écrivait
     * « Réception … ». Rien n'indiquait à qui saisit que la contrepartie
     * financière restait à faire.
     *
     * Le chemin d'achat AVEC trace financière existe : CreateFeedPurchase, qui
     * pose facture fournisseur et règlement — mais il porte sur l'aliment
     * consommé par les bandes, pas sur les ingrédients de la provenderie.
     *
     * ─── POURQUOI UN VERROU ───
     *
     * Le CMP est un lire-puis-écrire : ancienne valeur + valeur entrante, divisé
     * par la quantité totale. Deux corrections simultanées sur le même
     * ingrédient lisaient toutes deux l'ancien état, et la seconde écrasait la
     * première — la quantité ET le coût de l'une disparaissaient.
     */
    public function updateStock(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Saisie non autorisée.');

        $material = RawMaterial::findOrFail($id);

        $request->validate([
            'added_qty'      => 'required|numeric|min:0.01',
            'purchase_price' => 'required|numeric|min:0',
            'provider_id'    => 'nullable|exists:providers,id',
            // Même exigence que la sortie : une correction d'inventaire sans
            // motif est inaudible trois mois plus tard.
            'reason'         => 'required|string|max:255',
        ], [
            'reason.required' => "Indiquez le motif de la correction (réception, écart d'inventaire, retour…).",
        ]);

        return DB::transaction(function () use ($material, $request) {
            $material  = RawMaterial::lockForUpdate()->findOrFail($material->getKey());
            $addedQty  = (float) $request->added_qty;
            $entryValue = (float) $request->purchase_price;

            // Coût Moyen Pondéré
            $currentValue = (float) $material->stock_qty * (float) $material->unit_cost;
            $totalQty     = (float) $material->stock_qty + $addedQty;
            $newUnitCost  = $totalQty > 0 ? ($currentValue + $entryValue) / $totalQty : $material->unit_cost;

            $material->update([
                'stock_qty' => $totalQty,
                'unit_cost' => round($newUnitCost, 2),
            ]);

            if ($material->stock_id) {
                $origine = $request->provider_id
                    ? ' Origine: ' . Provider::whereKey($request->provider_id)->value('name') . '.'
                    : '';

                // Pas de `unit_price` ici : la colonne figure au $fillable du
                // modèle mais PAS en base — l'écrire fait éclater l'insertion.
                StockMovement::create([
                    'stock_id'   => $material->stock_id,
                    'user_id'    => \App\Models\User::systemActorId(),
                    'type'       => 'in',
                    'quantity'   => $addedQty,
                    'notes'      => "Correction d'inventaire {$material->name} +{$addedQty} {$material->unit}, CMP → "
                        . round($newUnitCost, 2) . " GNF. Motif: {$request->reason}.{$origine}",
                ]);
            }

            return back()->with('success', "Entrée enregistrée et CMP recalculé. L'achat, s'il y en a un, se saisit au module Dépenses.");
        });
    }

    /**
     * Mise à jour des valeurs nutritionnelles (labo).
     */
    public function updateNutrition(Request $request, $id): RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Action non autorisée.');

        $request->validate(array_merge(
            ['energy_kcal' => 'required|numeric|min:0', 'protein_rate' => 'required|numeric|min:0|max:100'],
            collect($this->nutrientRules())->except(['energy_kcal', 'protein_rate'])->all()
        ));

        $material = RawMaterial::findOrFail($id);
        $material->update($request->only($this->nutrientColumns()));

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
            // Le plafond « max:stock_qty » a été mesuré AVANT la transaction :
            // deux sorties concurrentes le passaient toutes deux et le stock
            // tombait sous zéro. On relit sous verrou et on refuse au besoin.
            $material = RawMaterial::lockForUpdate()->findOrFail($material->getKey());

            if ((float) $request->qty > (float) $material->stock_qty) {
                return back()->with('error', "Impossible : il ne reste que {$material->stock_qty} {$material->unit} en stock.");
            }

            $material->decrement('stock_qty', $request->qty);

            if ($material->stock_id) {
                StockMovement::create([
                    'stock_id' => $material->stock_id,
                    'user_id'  => \App\Models\User::systemActorId(),
                    'type'     => 'out',
                    'quantity' => $request->qty,
                    'notes'    => "Ajustement {$material->name} -{$request->qty} {$material->unit}. Motif: {$request->reason}",
                ]);
            }

            return back()->with('success', "Dépréciation de {$request->qty} {$material->unit} enregistrée.");
        });
    }
}
