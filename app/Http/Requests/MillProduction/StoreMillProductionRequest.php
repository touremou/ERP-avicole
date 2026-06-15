<?php

namespace App\Http\Requests\MillProduction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreMillProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('provenderie.C');
    }

    public function rules(): array
    {
        return [
            'formula_id'    => 'required|integer|exists:formulas,id',
            'machine_ids'   => 'required|array|min:1',
            'machine_ids.*' => 'integer|exists:mill_machines,id',
            'nb_bags'       => 'required|integer|min:1',
            'supervisor_id' => 'required|integer|exists:employees,id',
        ];
    }

    public function messages(): array
    {
        return [
            'formula_id.exists'   => 'Formule introuvable.',
            'machine_ids.required' => 'Au moins une machine est requise.',
            'nb_bags.min'          => 'Au moins 1 sac doit être produit.',
            'supervisor_id.exists' => 'Superviseur introuvable.',
        ];
    }
}
