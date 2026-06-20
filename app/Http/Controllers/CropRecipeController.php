<?php

namespace App\Http\Controllers;

use App\Models\CropRecipe;
use App\Models\CropTransformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Recettes de transformation (module: cultures) — standards d'agro-transformation
 * (intrants, produit fini visé, rendement de référence, conservation).
 */
class CropRecipeController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $recipes = CropRecipe::withCount('items')
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        return view('cultures.recipes.index', [
            'recipes' => $recipes,
            'types'   => CropTransformation::TYPES,
        ]);
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.recipes.create', ['types' => CropTransformation::TYPES]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'code'                    => 'nullable|string|max:50',
            'name'                    => 'required|string|max:255',
            'transformation_type'     => 'required|in:' . implode(',', array_keys(CropTransformation::TYPES)),
            'output_product'          => 'required|string|max:255',
            'output_unit'             => 'nullable|string|max:20',
            'expected_yield_percent'  => 'nullable|numeric|min:0|max:1000',
            'shelf_life_days'         => 'nullable|integer|min:0|max:3650',
            'estimated_cost'          => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string|max:1000',
            'items'                   => 'nullable|array',
            'items.*.input_product'   => 'nullable|string|max:255',
            'items.*.quantity'        => 'nullable|numeric|min:0',
            'items.*.unit'            => 'nullable|string|max:20',
        ]);

        $recipe = DB::transaction(function () use ($validated, $request) {
            $recipe = CropRecipe::create($validated);

            foreach ($request->input('items', []) as $item) {
                if (empty($item['input_product'])) {
                    continue;
                }
                $recipe->items()->create([
                    'input_product' => $item['input_product'],
                    'quantity'      => $item['quantity'] ?? 0,
                    'unit'          => $item['unit'] ?? 'kg',
                ]);
            }

            return $recipe;
        });

        return redirect()->route('crop-recipes.show', $recipe)
            ->with('success', "Recette « {$recipe->name} » créée.");
    }

    public function show(CropRecipe $cropRecipe)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $cropRecipe->load('items');

        return view('cultures.recipes.show', ['recipe' => $cropRecipe]);
    }

    public function destroy(CropRecipe $cropRecipe)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropRecipe->delete();

        return redirect()->route('crop-recipes.index')->with('success', 'Recette supprimée.');
    }
}
