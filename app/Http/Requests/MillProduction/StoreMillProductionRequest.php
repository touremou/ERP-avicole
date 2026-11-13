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
            'formula_id'    => ['required', 'integer', \App\Support\Ferme::existe('formulas')],
            'machine_ids'   => 'required|array|min:1',
            'machine_ids.*' => ['integer', \App\Support\Ferme::existe('mill_machines')],
            'nb_bags'       => 'required|integer|min:1',
            'supervisor_id' => ['required', 'integer', \App\Support\Ferme::employe()],
        ];
    }

    /** Formule active, machines opérationnelles (MillProduction::refusDeLancement). */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (\App\Models\MillProduction::refusDeLancement(
                (int) $this->input('formula_id'), (array) $this->input('machine_ids', []),
            ) as $champ => $message) {
                $validator->errors()->add($champ, $message);
            }
        });
    }

    public function messages(): array
    {
        return [
            'formula_id.exists'   => 'Formule introuvable.',
            'machine_ids.required' => 'Au moins une machine est requise.',
            'nb_bags.min'          => 'Au moins 1 sac doit être produit.',
        ];
    }
}
