<?php

namespace App\Http\Controllers;

use App\Actions\Stock\RecordStoredLotCheck;
use App\Models\CropTransformation;
use App\Models\Employee;
use App\Models\Harvest;
use App\Models\Stock;
use App\Models\StoredLot;
use App\Models\StoredLotCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * CONSERVATION — les lots gardés pour être vendus plus cher plus tard (T2).
 *
 * Registre de la décision de spéculer : prix-cible, échéance, contrôles
 * périodiques avec pesée. L'index sert de tableau de bord des paris en cours et
 * signale aussi les stocks conservés SANS suivi — c'est là que se perd l'argent
 * qu'on croyait gagner en attendant.
 */
class StoredLotController extends Controller
{
    public function index()
    {
        if (Gate::denies('logistique.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $lots = StoredLot::with(['stock:id,item_name,category,current_quantity', 'checks'])
            ->orderByRaw("CASE status WHEN 'en_stock' THEN 0 ELSE 1 END")
            ->orderBy('hold_until')
            ->get();

        $open = $lots->where('status', StoredLot::STATUS_EN_STOCK);

        return view('conservation.index', [
            'lots'   => $lots,
            'totals' => [
                'open_count'    => $open->count(),
                'value_at_cost' => round($open->sum(fn (StoredLot $l) => $l->value_at_cost), 2),
                'shrinkage_kg'  => round($open->sum(fn (StoredLot $l) => $l->total_shrinkage), 3),
                'to_check'      => $open->filter(fn (StoredLot $l) => $l->check_is_overdue)->count(),
                'past_deadline' => $open->filter(fn (StoredLot $l) => $l->is_past_deadline)->count(),
                'target_hit'    => $open->filter(fn (StoredLot $l) => $l->target_reached === true)->count(),
            ],
            // Stocks « produits finis » ou « récoltes » présents SANS lot de
            // conservation : la marchandise dort sans objectif ni surveillance.
            'untracked' => $this->untrackedStocks($lots->pluck('stock_id')->all()),
        ]);
    }

    public function create(Request $request)
    {
        if (Gate::denies('logistique.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        // Pré-remplissage depuis une source : récolte mise de côté, ou lot
        // transformé. On évite ainsi de ressaisir quantité et coût de revient.
        $harvest = $request->filled('harvest_id')
            ? Harvest::with('cropCycle')->find($request->input('harvest_id'))
            : null;
        $transformation = $request->filled('crop_transformation_id')
            ? CropTransformation::find($request->input('crop_transformation_id'))
            : null;

        return view('conservation.create', [
            'harvest'        => $harvest,
            'transformation' => $transformation,
            'prefill'        => $this->prefillFrom($harvest, $transformation),
            'stocks'         => Stock::whereIn('category', [Stock::CAT_RECOLTES, Stock::CAT_PRODUITS_FINIS])
                ->orderBy('item_name')->get(['id', 'item_name', 'unit', 'current_quantity', 'last_unit_price']),
        ]);
    }

    public function store(Request $request)
    {
        if (Gate::denies('logistique.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'stock_id'               => 'required|exists:stocks,id',
            'harvest_id'             => 'nullable|exists:harvests,id',
            'crop_transformation_id' => 'nullable|exists:crop_transformations,id',
            'label'                  => 'required|string|max:255',
            'quantity_initial'       => 'required|numeric|min:0.001',
            'unit'                   => 'nullable|string|max:20',
            'unit_cost'              => 'nullable|numeric|min:0',
            'target_unit_price'      => 'nullable|numeric|min:0',
            'hold_until'             => 'nullable|date|after:today',
            'check_interval_days'    => 'nullable|integer|min:1|max:180',
            'notes'                  => 'nullable|string|max:1000',
        ]);

        $stock = Stock::findOrFail($data['stock_id']);

        // On ne met pas en conservation plus que ce que le magasin contient :
        // le lot serait un pari sur une marchandise inexistante.
        if ((float) $data['quantity_initial'] > (float) $stock->current_quantity + 0.0001) {
            return back()->withInput()->with('error', sprintf(
                'Le stock « %s » ne contient que %s %s : impossible d\'en mettre %s en conservation.',
                $stock->item_name,
                number_format((float) $stock->current_quantity, 1, ',', ' '),
                $stock->unit,
                number_format((float) $data['quantity_initial'], 1, ',', ' '),
            ));
        }

        $lot = StoredLot::create($data + [
            'quantity_current'    => $data['quantity_initial'],
            'unit'                => $data['unit'] ?? $stock->unit ?? 'kg',
            // Coût de revient figé : à défaut de saisie, le CMP du jour.
            'unit_cost'           => $data['unit_cost'] ?? $stock->last_unit_price,
            'opened_at'           => now()->toDateString(),
            'check_interval_days' => $data['check_interval_days'] ?? 14,
            'status'              => StoredLot::STATUS_EN_STOCK,
        ]);

        return redirect()->route('stored-lots.show', $lot)
            ->with('success', "Lot « {$lot->label} » mis en conservation.");
    }

    public function show(StoredLot $storedLot)
    {
        if (Gate::denies('logistique.L')) {
            return back()->with('error', 'Accès restreint.');
        }

        $storedLot->load(['stock', 'checks.employee:id,first_name,last_name', 'harvest.cropCycle:id,code,crop_name', 'transformation']);

        return view('conservation.show', [
            'lot'        => $storedLot,
            'conditions' => StoredLotCheck::CONDITIONS,
            'actions'    => StoredLotCheck::ACTIONS,
            'employees'  => Employee::active()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
        ]);
    }

    /** Enregistre un contrôle périodique (pesée, état, cours du marché). */
    public function storeCheck(Request $request, StoredLot $storedLot, RecordStoredLotCheck $action)
    {
        if (Gate::denies('logistique.C')) {
            return back()->with('error', 'Action non autorisée.');
        }

        if (! $storedLot->is_open) {
            return back()->with('error', "Le lot « {$storedLot->label} » est clos : aucun contrôle possible.");
        }

        $data = $request->validate([
            'checked_at'       => 'nullable|date|before_or_equal:now',
            'weighed_quantity' => 'nullable|numeric|min:0',
            'condition'        => 'required|in:' . implode(',', array_keys(StoredLotCheck::CONDITIONS)),
            'action_taken'     => 'nullable|in:' . implode(',', array_keys(StoredLotCheck::ACTIONS)),
            'market_price'     => 'nullable|numeric|min:0',
            'employee_id'      => 'nullable|exists:employees,id',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $action->execute($storedLot, $data, Auth::id());

        return redirect()->route('stored-lots.show', $storedLot)
            ->with('success', 'Contrôle enregistré.');
    }

    /** Clôture manuelle : le lot est vendu, ou l'on renonce à la conservation. */
    public function close(Request $request, StoredLot $storedLot)
    {
        if (Gate::denies('logistique.M')) {
            return back()->with('error', 'Action non autorisée.');
        }

        $data = $request->validate([
            'status' => 'required|in:' . implode(',', [StoredLot::STATUS_VENDU, StoredLot::STATUS_CLOTURE]),
            'reason' => 'nullable|string|max:255',
        ]);

        // La clôture ne touche PAS l'inventaire : la vente le décrémente par son
        // propre chemin (déstockage à la validation). Doubler la sortie ici
        // ferait disparaître la marchandise deux fois.
        $storedLot->update([
            'status'        => $data['status'],
            'closed_at'     => now()->toDateString(),
            'closed_reason' => $data['reason'] ?? null,
        ]);

        return redirect()->route('stored-lots.index')
            ->with('success', "Lot « {$storedLot->label} » clôturé ({$storedLot->status_label}).");
    }

    /**
     * Valeurs proposées à l'ouverture d'un lot, depuis sa source.
     *
     * @return array<string, mixed>
     */
    private function prefillFrom(?Harvest $harvest, ?CropTransformation $transformation): array
    {
        if ($transformation) {
            return [
                'label'     => $transformation->output_product . ' — ' . $transformation->batch_number,
                'quantity'  => (float) $transformation->output_quantity,
                'unit'      => $transformation->output_unit,
                // Le coût de revient calculé par T1 : c'est LUI qui juge le pari.
                'unit_cost' => $transformation->output_unit_cost,
                'target'    => $transformation->output_unit_price,
                'item_name' => $transformation->output_stock_item ?? $transformation->output_product,
            ];
        }

        if ($harvest) {
            $cost = $harvest->cropCycle?->productionCostPerKg();

            return [
                'label'     => ($harvest->cropCycle?->crop_name ?? 'Récolte')
                               . ' — ' . $harvest->harvest_date->format('d/m/Y'),
                'quantity'  => $harvest->effective_weight_kg,
                'unit'      => 'kg',
                'unit_cost' => $cost > 0 ? $cost : null,
                'target'    => null,
                'item_name' => $harvest->stock_item_name ?? $harvest->cropCycle?->crop_name,
            ];
        }

        return ['label' => null, 'quantity' => null, 'unit' => 'kg', 'unit_cost' => null, 'target' => null, 'item_name' => null];
    }

    /**
     * Stocks conservables présents SANS lot de conservation ouvert.
     *
     * Le signal le plus utile de la page : de la marchandise qui dort sans
     * objectif de prix, sans échéance et sans contrôle. C'est exactement la
     * situation qu'on cherche à supprimer.
     *
     * @param  array<int, int>  $trackedStockIds
     */
    private function untrackedStocks(array $trackedStockIds)
    {
        return Stock::whereIn('category', [Stock::CAT_RECOLTES, Stock::CAT_PRODUITS_FINIS])
            ->where('current_quantity', '>', 0)
            ->whereNotIn('id', array_filter($trackedStockIds))
            ->orderByDesc('current_quantity')
            ->get(['id', 'item_name', 'category', 'unit', 'current_quantity', 'last_unit_price']);
    }
}
