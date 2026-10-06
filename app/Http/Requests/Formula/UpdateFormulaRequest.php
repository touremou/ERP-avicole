<?php

namespace App\Http\Requests\Formula;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('provenderie.M');
    }

    public function rules(): array
    {
        return [
            'name'                     => 'required|string|max:255',
            'target_type'              => 'required|string|max:100',
            'species_id'               => 'nullable|exists:species,id',
            'production_type_id'       => 'nullable|exists:production_types,id',
            'total_batch_weight'       => 'nullable|numeric|min:1',
            'instructions'             => 'nullable|string|max:2000',
            'ingredients'              => 'required|array|min:1',
            'ingredients.*.id'         => 'required|integer|exists:raw_materials,id',
            'ingredients.*.percentage' => 'required|numeric|min:0|max:100',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Le type de production appartient à l'espèce choisie (BatchIdentity) :
            // une formule « poulet » ne peut pas porter le type « Dinde de chair ».
            foreach (\App\Support\BatchIdentity::erreurs(
                $this->filled('species_id') ? (int) $this->input('species_id') : null,
                $this->filled('production_type_id') ? (int) $this->input('production_type_id') : null,
                null,
            ) as $champ => $message) {
                $validator->errors()->add($champ, $message);
            }

            $total = collect($this->input('ingredients', []))->sum('percentage');
            if (abs($total - 100) > 0.1) {
                $validator->errors()->add('ingredients', "Total des pourcentages : {$total}%. Attendu : 100%.");
            }
        });
    }
}
