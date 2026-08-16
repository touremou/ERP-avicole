<?php

namespace App\Http\Controllers;

use App\Actions\Crop\RecordCropTransformation;
use App\Models\CropCycle;
use App\Models\CropRecipe;
use App\Models\CropTransformation;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Transformation végétale (module: cultures) — agro-transformation des
 * récoltes en produits finis.
 */
class CropTransformationController extends Controller
{
    public function index()
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $transformations = CropTransformation::with(['cropCycle:id,crop_name,code', 'employee:id,first_name,last_name'])
            ->orderByDesc('production_date')
            ->paginate((int) setting('general.items_per_page', 20));

        $stats = [
            'count_30d'   => CropTransformation::where('production_date', '>=', now()->subDays(30))->count(),
            'output_30d'  => (float) CropTransformation::where('production_date', '>=', now()->subDays(30))->sum('output_quantity'),
            'avg_yield'   => (float) CropTransformation::where('production_date', '>=', now()->subDays(90))->avg('yield_percent'),
        ];

        return view('cultures.transformations.index', compact('transformations', 'stats'));
    }

    public function create()
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.transformations.create', [
            'types'     => CropTransformation::TYPES,
            'cycles'    => CropCycle::whereIn('status', [CropCycle::STATUS_EN_COURS, CropCycle::STATUS_RECOLTE, CropCycle::STATUS_TERMINE])
                ->orderByDesc('planting_date')->get(['id', 'crop_name', 'code']),
            'employees' => Employee::assignableInCurrentFarm()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'recipes'   => CropRecipe::active()->with('items')->orderBy('name')->get(),
            // Récoltes EN ATTENTE de transformation : la liste de travail réelle
            // de l'atelier. On la propose d'abord — choisir la récolte donne à la
            // fois la traçabilité et le coût matière, sans rien saisir de plus.
            'pendingHarvests' => \App\Models\Harvest::query()
                ->where('destination', \App\Models\Harvest::DEST_TRANSFORMATION)
                ->notEngaged()
                ->with('cropCycle:id,code,crop_name')
                ->orderByDesc('harvest_date')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request, RecordCropTransformation $action)
    {
        if (Gate::denies('cultures.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'crop_cycle_id'       => 'nullable|exists:crop_cycles,id',
            // Récolte précise engagée (T1) : porte la traçabilité au lot ET le
            // coût matière le plus juste (coût de production de SON cycle).
            'harvest_id'          => 'nullable|exists:harvests,id',
            'crop_recipe_id'      => 'nullable|exists:crop_recipes,id',
            'employee_id'         => 'nullable|exists:employees,id',
            'input_product'       => 'required|string|max:255',
            'output_product'      => 'required|string|max:255',
            'transformation_type' => 'required|in:' . implode(',', array_keys(CropTransformation::TYPES)),
            'input_quantity'      => 'required|numeric|min:0.001',
            'input_unit'          => 'nullable|string|max:20',
            'output_quantity'     => 'required|numeric|min:0',
            'output_unit'         => 'nullable|string|max:20',
            'production_date'     => 'required|date',
            'expiry_date'         => 'nullable|date|after_or_equal:production_date',
            'production_cost'     => 'nullable|numeric|min:0',
            'output_unit_price'   => 'nullable|numeric|min:0',
            'consumed_from_stock' => 'nullable|boolean',
            'input_stock_item'    => 'nullable|string|max:255',
            'synced_to_stock'     => 'nullable|boolean',
            'output_stock_item'   => 'nullable|string|max:255',
            'notes'               => 'nullable|string|max:1000',
        ]);

        /*
         * UNE RÉCOLTE NE S'ENGAGE QU'UNE FOIS.
         *
         * La synchro mobile refuse depuis toujours de re-transformer une
         * récolte : « sa matière est engagée ». Le web, lui, se contentait de
         * ne PAS la proposer dans la liste — une garde d'AFFICHAGE, pas une
         * règle. Un double envoi, un retour arrière ou un formulaire d'atelier
         * resté ouvert la franchissaient sans rien signaler.
         *
         * Mesuré : deux envois du même formulaire sur une récolte de 200 kg
         * engagent 400 kg de matière, imputent 800 000 GNF de coût matière à
         * un cycle qui n'a coûté que 400 000, et sortent 120 kg de produit
         * fini là où il n'y en a que 60. Le coût de revient du cycle et le
         * stock de l'atelier deviennent faux ensemble.
         */
        if (! empty($validated['harvest_id'])) {
            $harvest = \App\Models\Harvest::find($validated['harvest_id']);

            if ($harvest?->isEngaged()) {
                return back()->withInput()->with('error',
                    'La récolte du ' . $harvest->harvest_date->format('d/m/Y')
                    . ' a déjà été transformée — sa matière est engagée.'
                );
            }
        }

        $transformation = $action->execute($validated);

        return redirect()->route('crop-transformations.index')
            ->with('success', "Transformation {$transformation->batch_number} enregistrée (rendement {$transformation->yield_percent}%).");
    }

    public function show(CropTransformation $cropTransformation)
    {
        if (Gate::denies('cultures.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $cropTransformation->load(['cropCycle', 'harvest', 'recipe:id,name', 'employee:id,first_name,last_name']);

        return view('cultures.transformations.show', ['transformation' => $cropTransformation]);
    }

    public function edit(CropTransformation $cropTransformation)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        return view('cultures.transformations.edit', [
            'transformation' => $cropTransformation,
            'types'          => CropTransformation::TYPES,
            'cycles'         => CropCycle::whereIn('status', [CropCycle::STATUS_EN_COURS, CropCycle::STATUS_RECOLTE, CropCycle::STATUS_TERMINE])
                ->orderByDesc('planting_date')->get(['id', 'crop_name', 'code']),
            'employees'      => Employee::assignableInCurrentFarm()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    /**
     * Mise à jour d'une transformation.
     *
     * Le stock est réconcilié par CropTransformationObserver::updated() —
     * l'ancienne valeur est ANNULÉE puis la nouvelle appliquée, comme pour les
     * récoltes et les intrants. L'en-tête disait ici que la synchro « n'est pas
     * rejouée pour éviter les doublons » : le motif était juste (rejouer la
     * création doublerait), mais la conclusion — ne rien faire — laissait la
     * fiche affirmer un stock qu'elle n'avait pas écrit.
     */
    public function update(Request $request, CropTransformation $cropTransformation)
    {
        if (Gate::denies('cultures.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $validated = $request->validate([
            'crop_cycle_id'       => 'nullable|exists:crop_cycles,id',
            'employee_id'         => 'nullable|exists:employees,id',
            'input_product'       => 'required|string|max:255',
            'output_product'      => 'required|string|max:255',
            'transformation_type' => 'required|in:' . implode(',', array_keys(CropTransformation::TYPES)),
            'input_quantity'      => 'required|numeric|min:0.001',
            'input_unit'          => 'nullable|string|max:20',
            'output_quantity'     => 'required|numeric|min:0',
            'output_unit'         => 'nullable|string|max:20',
            'production_date'     => 'required|date',
            'expiry_date'         => 'nullable|date|after_or_equal:production_date',
            'production_cost'     => 'nullable|numeric|min:0',
            'output_unit_price'   => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string|max:1000',
        ]);

        // Rendement recalculé pour rester cohérent avec les quantités saisies.
        $input = (float) $validated['input_quantity'];
        $validated['yield_percent'] = $input > 0 ? round((float) $validated['output_quantity'] / $input * 100, 2) : 0;

        $cropTransformation->update($validated);

        return redirect()->route('crop-transformations.show', $cropTransformation)
            ->with('success', 'Transformation mise à jour.');
    }

    public function destroy(CropTransformation $cropTransformation)
    {
        if (Gate::denies('cultures.S')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $cropTransformation->delete();

        return redirect()->route('crop-transformations.index')->with('success', 'Transformation supprimée.');
    }
}
