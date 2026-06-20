<?php

namespace App\Http\Requests\Batch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation pour la clôture d'un lot.
 */
class CloseBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.M');
    }

    public function rules(): array
    {
        return [
            'actual_sell_price_per_unit' => 'required|numeric|min:0',
            'additional_costs'          => 'nullable|numeric|min:0',
            'closing_date'              => 'required|date|before_or_equal:today',
            'observations'              => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'actual_sell_price_per_unit.required' => 'Le prix de vente unitaire est requis.',
            'closing_date.required'               => 'La date de clôture est requise.',
            'closing_date.before_or_equal'        => 'La date de clôture ne peut pas être dans le futur.',
        ];
    }
}
