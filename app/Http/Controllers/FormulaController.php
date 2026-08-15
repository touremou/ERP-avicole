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
        Formula::attachNorms($formulas);
        $norms = FoodNorm::active()->orderBy('name')->get();

        return view('provenderie.formulas.index', compact('formulas', 'norms'));
    }

    public function create(): View|RedirectResponse
    {
        if (Gate::denies('provenderie.C')) return back()->with('error', 'Privilèges insuffisants.');

        $materials = RawMaterial::where('is_active', true)->orderBy('name')->get();
        $norms = FoodNorm::active()->orderBy('name')->get();
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
        $formula->load('items.rawMaterial', 'productionType');

        // Une seule source pour l'analyse et le verdict : le modèle, qui lit le
        // référentiel. La fiche portait auparavant sa propre pondération et ses
        // propres cibles de repli (3000 kcal / 20 % / 1,1 %), affichées sous
        // l'étiquette « Cible (Norme) » alors qu'aucune norme n'était rattachée.
        $comparison = $formula->nutritionalComparison();
        $verdict    = $formula->economicVerdict();
        $norm       = $formula->norm();
        $candidates = $formula->normCandidates();

        return view('provenderie.formulas.show', compact('formula', 'norm', 'comparison', 'verdict', 'candidates'));
    }

    public function edit(Formula $formula): View|RedirectResponse
    {
        if (Gate::denies('provenderie.M')) return back()->with('error', 'Modification non autorisée.');

        $formula->load('items.rawMaterial');
        $rawMaterials = RawMaterial::orderBy('name')->get();
        $productionTypes = ProductionType::active()->with('species')->orderBy('species_id')->get();
        // L'écran d'OPTIMISATION était le seul sans cibles à l'écran : on y
        // travaillait à l'aveugle alors que la création, elle, les affichait.
        $norms = FoodNorm::active()->orderBy('name')->get();

        return view('provenderie.formulas.edit', compact('formula', 'rawMaterials', 'productionTypes', 'norms'));
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
     * MODÈLE DE FICHIER pour l'import du référentiel normé.
     *
     * L'écran proposait « /templates/norms_template.xlsx », un fichier qui
     * n'existe pas dans le dépôt : le lien renvoyait un 404 et il fallait
     * deviner l'ordre des dix colonnes d'un import positionnel — une colonne
     * décalée écrivait l'énergie dans la protéine sans un mot. Le modèle est
     * maintenant EXTRAIT du référentiel en place : les colonnes sont
     * nécessairement dans le bon ordre, et le fichier téléchargé, corrigé puis
     * réimporté met à jour les normes au lieu de les dupliquer.
     */
    public function normsTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $norms = FoodNorm::orderBy('animal_type')->orderBy('phase')->get();

        return response()->streamDownload(function () use ($norms) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel ouvre l'UTF-8 correctement

            \App\Support\CsvExport::putRow($out, [
                'Nom', 'Type animal (clef)', 'Phase (clef)',
                'Énergie EM kcal/kg', 'Protéine brute %', 'Lysine %',
                'Méthionine %', 'Calcium %', 'Phosphore %',
                'Prix cible ' . currency() . '/kg',
            ], ';');

            foreach ($norms as $norm) {
                \App\Support\CsvExport::putRow($out, [
                    $norm->name, $norm->animal_type, $norm->phase,
                    $norm->target_em, $norm->target_pb, $norm->target_lys,
                    $norm->target_meth, $norm->target_ca, $norm->target_p,
                    $norm->target_price_kg,
                ], ';');
            }

            fclose($out);
        }, 'normes-nutritionnelles.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
