<?php

namespace App\Http\Requests\Formula;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation création de formule.
 * Corrige P-03/P-07 : format unifié (ingredients[].id + ingredients[].percentage).
 */
class StoreFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        return [
            'name'                     => 'required|string|max:255',
            'code'                     => 'required|string|max:50|unique:formulas,code',
            'target_type'              => 'required|string|max:100',
            'poultry_type'             => 'nullable|string|in:Chair,Ponte',
            'total_batch_weight'       => 'required|numeric|min:1',
            'instructions'             => 'nullable|string|max:2000',
            'ingredients'              => 'required|array|min:1',
            'ingredients.*.id'         => 'required|integer|exists:raw_materials,id',
            'ingredients.*.percentage' => 'required|numeric|min:0|max:100',
        ];
    }

    /**
     * La somme des pourcentages doit être 100% (tolérance 0.1%).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $ingredients = $this->input('ingredients', []);
            $total = collect($ingredients)->sum('percentage');

            if (abs($total - 100) > 0.1) {
                $validator->errors()->add(
                    'ingredients',
                    "La somme des pourcentages est de {$total}%. Elle doit être de 100%."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'code.unique'                  => 'Ce code de formule existe déjà.',
            'ingredients.required'         => 'Au moins un ingrédient est requis.',
            'ingredients.*.id.exists'      => 'Matière première introuvable.',
            'ingredients.*.percentage.max' => 'Un ingrédient ne peut pas dépasser 100%.',
        ];
    }
}
