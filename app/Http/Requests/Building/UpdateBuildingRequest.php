<?php

namespace App\Http\Requests\Building;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.M');
    }

    public function rules(): array
    {
        // La liaison de modèle injecte une instance Building dans la route :
        // on passe l'objet (ou son id) à ignore() plutôt que de le concaténer
        // dans une chaîne « unique:... » (sinon le modèle est sérialisé en JSON
        // et corrompt la requête SQL générée).
        return [
            'name'                    => ['required', 'string', 'max:255', Rule::unique('buildings', 'name')->ignore($this->route('building'))],
            'type'                    => ['required', 'in:poussiniere,chair,ponte,reproducteur,mixte,bergerie,chevrerie,etable,bassin,lapiniere,porcherie'],
            'surface'                 => ['required', 'numeric', 'min:1'],
            'capacity'                => ['required', 'integer', 'min:1'],
            'status'                  => ['required', Rule::in(Building::STATUSES)],
            'description'             => ['nullable', 'string'],
            'disinfection_started_at' => ['nullable', 'date'],
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