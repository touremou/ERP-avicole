<?php

namespace App\Http\Requests\Batch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation pour la création d'un nouveau lot.
 *
 * Gère les variantes :
 * - Standard (chair, ponte, poussinière) : qty_alive requis
 * - Reproducteur : qty_males + qty_females requis
 *
 * @see AUDIT_MODULE_LOTS.md — Q-02 (extraction validation inline)
 */
class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.C');
    }

    public function rules(): array
    {
        $isRepro = in_array($this->input('type'), ['repro', 'reproducteur']);

        return [
            'code'               => 'required|string|max:50|unique:batches,code',
            'building_id'        => 'required|integer|exists:buildings,id',
            'type'               => 'required|in:chair,ponte,poussiniere,reproducteur,engraissement',
            'model_name'         => 'required|string|max:100',
            'employee_id'        => 'required|integer|exists:employees,id',
            'provider_id'        => 'required|integer|exists:providers,id',
            'protocol_id'        => 'nullable|integer|exists:protocols,id',
            'allocated_surface'  => 'nullable|numeric|min:0.1',
            'arrival_date'       => 'required|date|before_or_equal:today',
            'buy_price_per_unit' => 'required|numeric|min:0',
            'avg_weight_start'   => 'nullable|numeric|min:0',
            'observations'       => 'nullable|string|max:2000',
            'photo_path'         => 'nullable|string|max:255',
            'species_id'         => 'nullable|integer|exists:species,id',
            'production_type_id' => 'nullable|integer|exists:production_types,id',

            // Effectifs — conditionnels au type
            'qty_alive'   => $isRepro ? 'nullable|integer|min:0' : 'required|integer|min:1',
            'qty_dead'    => 'nullable|integer|min:0',
            'qty_males'   => $isRepro ? 'required|integer|min:0' : 'nullable|integer|min:0',
            'qty_females' => $isRepro ? 'required|integer|min:1' : 'nullable|integer|min:0',

            // Vaccinations
            'vaccination_received' => 'nullable|boolean',
            'vaccination_details'  => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique'              => 'Ce code de lot existe déjà.',
            'building_id.exists'       => 'Bâtiment introuvable.',
            'type.in'                  => 'Type de production invalide.',
            'qty_alive.required'       => 'L\'effectif vivant est requis pour ce type de lot.',
            'qty_alive.min'            => 'L\'effectif doit être d\'au moins 1 sujet.',
            'qty_females.required'     => 'Le nombre de femelles est requis pour un lot reproducteur.',
            'qty_females.min'          => 'Il faut au moins 1 femelle pour un lot reproducteur.',
            'arrival_date.before_or_equal' => 'La date d\'arrivée ne peut pas être dans le futur.',
        ];
    }

    /**
     * Prépare les données avant validation.
     * Calcule les champs dérivés (initial_quantity, mating_ratio, coûts).
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('type');
        $isRepro = in_array($type, ['repro', 'reproducteur']);

        if ($isRepro) {
            $males = (int) ($this->input('qty_males', 0));
            $females = (int) ($this->input('qty_females', 0));
            $total = $males + $females;

            $this->merge([
                'qty_alive' => $total,
                'mating_ratio' => $females > 0 ? round(($males / $females) * 100, 2) : 0,
            ]);
        }

        // Trim du code
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
