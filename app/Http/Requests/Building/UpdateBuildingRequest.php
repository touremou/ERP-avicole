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
            // Même liste que le sélecteur, y compris le type COURANT : une
            // bergerie dont l'espèce a été désactivée doit rester
            // enregistrable telle quelle, sinon toute modification de sa
            // capacité serait refusée sur un champ que l'utilisateur n'a pas
            // touché. Cf. Building::typesSaisissables().
            'type'                    => ['required', Rule::in(array_keys(
                Building::typesSaisissables($this->route('building')?->type)
            ))],
            'surface'                 => ['required', 'numeric', 'min:1'],
            'capacity'                => ['required', 'integer', 'min:1'],
            'status'                  => ['required', Rule::in(Building::STATUSES)],
            'water_source_id'         => ['nullable', 'integer', 'exists:water_sources,id'],
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