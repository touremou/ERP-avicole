<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use App\Models\ProductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation pour la modification d'un lot existant.
 *
 * RÈGLE CRITIQUE (B-03/B-04) : current_quantity et qty_alive ne sont
 * JAMAIS modifiables via ce formulaire. L'effectif ne change que via :
 * - DailyCheck (mortalité, quarantaine)
 * - Transfert (scission)
 * - Commande batches:rebuild-quantities (réconciliation)
 *
 * Corrige aussi B-08 : vérification Gate::allows('M') dans authorize().
 */
class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.M');
    }

    public function rules(): array
    {
        $batch = $this->route('batch');
        $batchId = is_object($batch) ? $batch->id : $batch;
        $isRepro = in_array($this->input('type'), ['repro', 'reproducteur']);

        $validTypes = ProductionType::pluck('slug')
            ->merge(['chair', 'ponte', 'poussiniere', 'reproducteur', 'engraissement'])
            ->unique()
            ->values()
            ->toArray();

        return [
            'type'               => ['required', Rule::in($validTypes)],
            'model_name'         => 'nullable|string|max:100',
            'building_id'        => 'required|integer|exists:buildings,id',
            'employee_id'        => 'required|integer|exists:employees,id',
            'provider_id'        => 'required|integer|exists:providers,id',
            'protocol_id'        => 'nullable|integer|exists:protocols,id',
            'allocated_surface'  => 'nullable|numeric|min:0.1',
            'buy_price_per_unit' => 'required|numeric|min:0',
            'arrival_date'       => 'required|date',
            'status'             => ['required', Rule::in(Batch::EDITABLE_STATUSES)],
            'observations'       => 'nullable|string|max:2000',
            'species_id'         => 'nullable|integer|exists:species,id',
            'production_type_id' => 'nullable|integer|exists:production_types,id',

            // Reproducteurs : on peut corriger la répartition M/F
            'qty_males'   => $isRepro ? 'nullable|integer|min:0' : 'nullable',
            'qty_females' => $isRepro ? 'nullable|integer|min:0' : 'nullable',

            // INTERDITS — ces champs sont VOLONTAIREMENT absents des règles.
            // Si quelqu'un les soumet, ils seront ignorés par l'Action (liste blanche).
            // 'initial_quantity' => INTERDIT après création
            // 'current_quantity' => INTERDIT (géré par observers)
            // 'qty_alive'        => INTERDIT (accessor)
            // 'qty_dead'         => INTERDIT après création (mortalité d'arrivage figée)
        ];
    }

    public function messages(): array
    {
        return [
            'type.in'            => 'Type de production invalide.',
            'building_id.exists' => 'Bâtiment introuvable.',
            'status.in'          => 'Statut invalide. Valeurs autorisées : ' . implode(', ', Batch::EDITABLE_STATUSES) . '.',
        ];
    }
}
