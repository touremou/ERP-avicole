<?php

namespace App\Http\Controllers;

use App\Models\Formula;
use App\Models\RawMaterial;
use App\Models\FoodNorm;
use App\Models\ProductionType;
use App\Actions\Formula\CreateFormula;
use App\Actions\Formula\UpdateFormula;
use App\Http\Requests\Formula\StoreFormulaRequest;
use App\Http\Requests\Formula\UpdateFormulaRequest;
use App\Imports\FoodNormImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class FormulaController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.L')) return redirect()->route('dashboard')->with('error', 'Accès non autorisé.');

        $formulas = Formula::with(['items.rawMaterial'])->latest()->get();
        $norms = FoodNorm::where('is_active', true)->get();

        return view('provenderie.formulas.index', compact('formulas', 'norms'));
    }

    public function create(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Privilèges insuffisants.');

        $materials = RawMaterial::where('is_active', true)->orderBy('name')->get();
        $norms = FoodNorm::where('is_active', true)->orderBy('name')->get();
        // Types de production de toutes les espèces (cible multiespèces de l'aliment).
        $productionTypes = ProductionType::active()->with('species')->orderBy('species_id')->get();

        return view('provenderie.formulas.create', compact('materials', 'norms', 'productionTypes'));
    }

    /**
     * P-03/P-07 corrigés : format unifié (ingredients[].id + ingredients[].percentage).
     */
    public function store(StoreFormulaRequest $request, CreateFormula $action): RedirectResponse
    {
        $formula = $action->execute($request->validated());

        return redirect()->route('formulas.index')
            ->with('success', "Formule {$formula->name} enregistrée.");
    }

    public function show(Formula $formula): View
    {
        $formula->load('items.rawMaterial');

        $norm = FoodNorm::where('animal_type', $formula->target_type)->first();

        // Analyse nutritionnelle pondérée
        $stats = [
            'energy'  => $formula->items->sum(fn($i) => ($i->percentage / 100) * ($i->rawMaterial->energy_kcal ?? 0)),
            'protein' => $formula->items->sum(fn($i) => ($i->percentage / 100) * ($i->rawMaterial->protein_rate ?? 0)),
            'lysine'  => $formula->items->sum(fn($i) => ($i->percentage / 100) * ($i->rawMaterial->lysine_rate ?? 0)),
            'cost'    => $formula->items->sum(fn($i) => ($i->percentage / 100) * ($i->rawMaterial->unit_cost ?? 0)),
        ];

        $chartData = [
            'labels' => ['Énergie (EM)', 'Protéines (PB)', 'Lysine'],
            'real'   => [round($stats['energy']), round($stats['protein'], 1), round($stats['lysine'], 2)],
            'target' => [
                $norm->target_em ?? 3000,
                $norm->target_pb ?? 20,
                $norm->target_lys ?? 1.1,
            ],
        ];

        return view('provenderie.formulas.show', compact('formula', 'norm', 'stats', 'chartData'));
    }

    public function edit(Formula $formula): View|RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Modification non autorisée.');

        $formula->load('items.rawMaterial');
        $rawMaterials = RawMaterial::orderBy('name')->get();
        $productionTypes = ProductionType::active()->with('species')->orderBy('species_id')->get();

        return view('provenderie.formulas.edit', compact('formula', 'rawMaterials', 'productionTypes'));
    }

    public function update(UpdateFormulaRequest $request, Formula $formula, UpdateFormula $action): RedirectResponse
    {
        $action->execute($formula, $request->validated());

        return redirect()->route('formulas.show', $formula)
            ->with('success', 'Structure nutritionnelle mise à jour.');
    }

    /**
     * Import du référentiel normé.
     * P-08 corrigé : DB::transaction au lieu de beginTransaction/commit/rollBack manuel.
     */
    public function importNorms(Request $request): RedirectResponse
    {
        if (Gate::denies('provenderie.S')) return back()->with('error', 'Seul un administrateur peut modifier le référentiel.');

        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv,txt|max:4096',
        ]);

        try {
            DB::transaction(function () use ($request) {
                $file = $request->file('file');
                $ext = strtolower($file->getClientOriginalExtension());

                if (in_array($ext, ['csv', 'txt'])) {
                    Excel::import(new FoodNormImport, $file, null, \Maatwebsite\Excel\Excel::CSV);
                } else {
                    Excel::import(new FoodNormImport, $file);
                }
            });

            return back()->with('success', 'Normes nutritionnelles mises à jour.');
        } catch (\Exception $e) {
            Log::error("Import normes échoué : {$e->getMessage()}");
            return back()->with('error', 'Erreur dans le fichier : ' . $e->getMessage());
        }
    }

    /**
     * P-12 : vérifie la relation productions avant suppression.
     */
    public function destroy(Formula $formula): RedirectResponse
    {
        if (Gate::denies('provenderie.S')) return back()->with('error', 'Suppression interdite.');

        if ($formula->productions()->exists()) {
            return back()->with('error', "Archivage requis : cette formule a déjà été produite. La supprimer compromettrait la traçabilité.");
        }

        DB::transaction(function () use ($formula) {
            $formula->items()->delete();
            $formula->delete();
        });

        return redirect()->route('formulas.index')->with('success', 'Formule supprimée.');
    }
}
