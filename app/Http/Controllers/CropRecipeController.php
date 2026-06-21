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

    public function edit(CropRecipe $cropRecipe)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropRecipe->load('items');

        return view('cultures.recipes.edit', [
            'recipe' => $cropRecipe,
            'types'  => CropTransformation::TYPES,
        ]);
    }

    public function update(Request $request, CropRecipe $cropRecipe)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'name'                   => 'required|string|max:255',
            'transformation_type'    => 'required|in:' . implode(',', array_keys(CropTransformation::TYPES)),
            'output_product'         => 'required|string|max:255',
            'output_unit'            => 'nullable|string|max:20',
            'expected_yield_percent' => 'nullable|numeric|min:0|max:1000',
            'shelf_life_days'        => 'nullable|integer|min:0|max:3650',
            'estimated_cost'         => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string|max:1000',
            'is_active'              => 'nullable|boolean',
        ]);
        $validated['is_active'] = $request->boolean('is_active', true);

        $cropRecipe->update($validated);

        return back()->with('success', 'Recette mise à jour.');
    }

    // ── IMPORT CSV ─────────────────────────────────────────────────────────────

    public function importForm()
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.recipes.import', ['types' => CropTransformation::TYPES]);
    }

    public function importStore(Request $request)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $path       = $request->file('file')->getRealPath();
        $handle     = fopen($path, 'r');
        $created    = 0;
        $header     = null;
        $validTypes = array_keys(CropTransformation::TYPES);

        while (($row = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map('strtolower', array_map('trim', $row));
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));
            $name = $data['name'] ?? null;
            $type = $data['transformation_type'] ?? null;

            if (! $name || ! in_array($type, $validTypes, true)) {
                continue;
            }

            $recipe = CropRecipe::firstOrCreate(
                ['name' => $name],
                [
                    'transformation_type' => $type,
                    'output_product'      => $data['output_product'] ?? $name,
                    'notes'               => $data['description'] ?? null,
                ]
            );

            if ($recipe->wasRecentlyCreated) {
                $created++;

                foreach (array_filter(explode(';', $data['ingredients'] ?? '')) as $ingredient) {
                    $parts = explode(':', trim($ingredient));
                    if (count($parts) < 2) {
                        continue;
                    }
                    $recipe->items()->create([
                        'input_product' => trim($parts[0]),
                        'quantity'      => (float) ($parts[1] ?? 0),
                        'unit'          => trim($parts[2] ?? 'kg'),
                    ]);
                }
            }
        }

        fclose($handle);

        return redirect()->route('crop-recipes.index')
            ->with('success', "{$created} recette(s) importée(s).");
    }
}
