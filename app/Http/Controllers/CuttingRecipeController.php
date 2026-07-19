<?php

namespace App\Http\Controllers;

use App\Models\CuttingRecipe;
use App\Models\CuttingRecipeLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recettes de désassemblage (BOM inversée) — paramétrage par ferme : un
 * article brut génère co-produits / sous-produits / déchets, avec rendements
 * attendus (aide de saisie + contrôle d'écart) et coefficients de valeur
 * (répartition des coûts conjoints). En l'absence de recette, la nomenclature
 * de config/butchery.php reste le repli.
 */
class CuttingRecipeController extends Controller
{
    public function index()
    {
        if (Gate::denies('abattoir.L')) return redirect()->route('dashboard')->with('error', 'Accès restreint.');

        $families = array_keys(config('butchery.cuts', []));
        $recipes = CuttingRecipe::with('lines')->get()->keyBy('species_family');

        return view('slaughter.recipes.index', compact('families', 'recipes'));
    }

    /** Matérialise la recette d'une famille depuis la nomenclature (éditable ensuite). */
    public function seed(string $family)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        if (! array_key_exists($family, config('butchery.cuts', []))) {
            return back()->with('error', __('Famille d\'espèce inconnue.'));
        }
        if (CuttingRecipe::where('species_family', $family)->exists()) {
            return back()->with('error', __('Une recette existe déjà pour cette famille.'));
        }

        $recipe = CuttingRecipe::seedFromNomenclature($family);

        return redirect()->route('slaughter.recipes.edit', $recipe)
            ->with('success', __('Recette créée depuis la nomenclature — ajustez rendements et coefficients de valeur.'));
    }

    public function edit(CuttingRecipe $recipe)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $recipe->load('lines');

        return view('slaughter.recipes.edit', compact('recipe'));
    }

    public function update(Request $request, CuttingRecipe $recipe)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'is_active' => 'nullable|boolean',
            'lines'                            => 'nullable|array',
            'lines.*.label'                    => 'required|string|max:255',
            'lines.*.output_type'              => 'required|in:' . implode(',', array_keys(CuttingRecipeLine::OUTPUT_TYPES)),
            'lines.*.expected_yield_percent'   => 'nullable|numeric|min:0|max:100',
            'lines.*.value_coefficient'        => 'nullable|numeric|min:0',
            'lines.*.default_destination'      => 'required|in:stock_frais,stock_congele,transformation,vente_directe,dechet',
            'lines.*.default_packaging'        => 'nullable|in:' . implode(',', \App\Models\CutProduct::PACKAGINGS),
            'lines.*.default_calibre'          => 'nullable|string|max:40',
            'lines.*.is_default'               => 'nullable|boolean',
        ]);

        // Garde-fou balance de masse : la somme des rendements attendus ne peut
        // pas dépasser 100 % (une découpe ne crée pas de matière).
        $totalPct = collect($validated['lines'] ?? [])->sum(fn ($l) => (float) ($l['expected_yield_percent'] ?? 0));
        if ($totalPct > 100.001) {
            return back()->withErrors([
                'lines' => __('La somme des rendements attendus (:pct %) dépasse 100 % — une découpe ne peut pas produire plus de matière qu\'elle n\'en reçoit.', ['pct' => number_format($totalPct, 1)]),
            ])->withInput();
        }

        $recipe->update([
            'name'      => $validated['name'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        foreach ($validated['lines'] ?? [] as $lineId => $attrs) {
            $line = $recipe->lines()->whereKey($lineId)->first();
            if (! $line) continue; // ligne d'une autre recette/ferme : ignorée
            $line->update([
                'label'                  => $attrs['label'],
                'output_type'            => $attrs['output_type'],
                'expected_yield_percent' => $attrs['expected_yield_percent'] ?? null,
                'value_coefficient'      => $attrs['value_coefficient'] ?? null,
                'default_destination'    => $attrs['default_destination'],
                'default_packaging'      => $attrs['default_packaging'] ?? null,
                'default_calibre'        => $attrs['default_calibre'] ?? null,
                'is_default'             => (bool) ($attrs['is_default'] ?? false),
            ]);
        }

        return back()->with('success', __('Recette enregistrée.'));
    }

    public function storeLine(Request $request, CuttingRecipe $recipe)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        $validated = $request->validate([
            'cut_code' => 'required|string|max:40|regex:/^[a-z0-9_]+$/',
            'label'    => 'required|string|max:255',
            'output_type' => 'required|in:' . implode(',', array_keys(CuttingRecipeLine::OUTPUT_TYPES)),
        ]);

        if ($recipe->lines()->where('cut_code', $validated['cut_code'])->exists()) {
            return back()->withErrors(['cut_code' => __('Ce code de morceau existe déjà dans la recette.')])->withInput();
        }

        $recipe->lines()->create($validated + [
            'default_destination' => $validated['output_type'] === CuttingRecipeLine::TYPE_DECHET ? 'dechet' : 'stock_frais',
            'is_default'          => false,
            'sort_order'          => ($recipe->lines()->max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', __('Morceau ajouté à la recette.'));
    }

    public function destroyLine(CuttingRecipe $recipe, CuttingRecipeLine $line)
    {
        if (Gate::denies('abattoir.M')) return back()->with('error', 'Action non autorisée.');

        if ((int) $line->cutting_recipe_id !== (int) $recipe->id) {
            return back()->with('error', __('Ligne inconnue pour cette recette.'));
        }

        $line->delete();

        return back()->with('success', __('Morceau retiré de la recette.'));
    }
}
