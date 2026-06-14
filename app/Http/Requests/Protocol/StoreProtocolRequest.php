<?php

namespace App\Http\Requests\Protocol;

use App\Models\ProductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        // Types de production de toutes les espèces (volaille et autres),
        // pour permettre la création de protocoles non-volailles.
        $allowedTypes = ProductionType::query()->pluck('slug')->unique()->values()->all();

        return [
            'name'                => 'required|string|max:255',
            'type'                => ['required', Rule::in($allowedTypes)],
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