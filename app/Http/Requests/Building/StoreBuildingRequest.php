<?php

namespace App\Http\Requests\Building;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.C');
    }

    public function rules(): array
    {
        return [
            // Unicité sur les bâtiments NON supprimés : recréer un nom d'un
            // bâtiment archivé (soft-deleted) ne doit pas être bloqué.
            'name'        => ['required', 'string', 'max:255', Rule::unique('buildings', 'name')->whereNull('deleted_at')],
            // Le serveur refuse ce que le formulaire n'offre plus : la liste
            // suit les espèces actives, et n'est déclarée qu'une fois
            // (Building::TYPES). Écrite ici en dur, elle finissait par ne plus
            // correspondre aux écrans — c'est ainsi qu'« etable » s'est retrouvé
            // enregistrable mais absent des filtres.
            'type'        => ['required', Rule::in(array_keys(Building::typesSaisissables()))],
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