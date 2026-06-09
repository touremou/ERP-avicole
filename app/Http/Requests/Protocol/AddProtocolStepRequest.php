<?php

namespace App\Http\Requests\Protocol;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AddProtocolStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('M');
    }

    public function rules(): array
    {
        return [
            'day_number'  => 'required|integer|min:0',
            'action_name' => 'required|string|max:255',
            'type'        => 'required|in:Vaccin,Traitement,Vitamine,Désinfection',
            'method'      => 'nullable|string',
        ];
    }
}