<?php

namespace App\Http\Requests\Building;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.C');
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', 'unique:buildings,name'],
            'type'        => ['required', 'in:poussiniere,chair,ponte,reproducteur,mixte,bergerie,chevrerie,etable,bassin,lapiniere,porcherie'],
            'surface'     => ['required', 'numeric', 'min:1'],
            'capacity'    => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string']
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ce nom de bâtiment est déjà attribué à une infrastructure.',
            'type.in'     => 'Le type de production sélectionné est invalide.',
        ];
    }
}