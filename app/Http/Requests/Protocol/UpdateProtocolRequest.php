<?php

namespace App\Http\Requests\Protocol;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('M');
    }

    public function rules(): array
    {
        return [
            'name'                => 'required|string|max:255',
            'type'                => 'required|in:chair,ponte,poussiniere,reproducteur',
            'strain'              => 'nullable|string|max:100',
            'description'         => 'nullable|string',
            'steps'               => 'nullable|array',
            'steps.*.day_number'  => 'required_with:steps|integer|min:0',
            'steps.*.action_name' => 'required_with:steps|string|max:255',
            'steps.*.type'        => 'required_with:steps|in:Vaccin,Traitement,Vitamine,Désinfection',
            'steps.*.method'      => 'nullable|string',
        ];
    }
}