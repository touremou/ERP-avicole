<?php

namespace App\Http\Requests\Building;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.M');
    }

    public function rules(): array
    {
        $buildingId = $this->route('building');

        return [
            'name'                    => ['required', 'string', 'max:255', 'unique:buildings,name,' . $buildingId],
            'type'                    => ['required', 'in:poussiniere,chair,ponte,reproducteur,mixte'],
            'surface'                 => ['required', 'numeric', 'min:1'],
            'capacity'                => ['required', 'integer', 'min:1'],
            'status'                  => ['required', 'in:Vide,Occupé,En désinfection,Disponible'],
            'disinfection_started_at' => ['nullable', 'date'],
        ];
    }
}