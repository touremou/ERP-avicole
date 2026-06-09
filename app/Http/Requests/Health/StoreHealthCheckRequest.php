<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreHealthCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.C');

    }

    public function rules(): array
    {
        return [
            'batch_id'            => ['required', 'exists:batches,id'],
            'intervention_date'   => ['required', 'date', 'before_or_equal:today'], 
            'type'                => ['required', 'in:Vaccin,Traitement,Vitamine,Désinfection'],
            'product_name'        => ['required', 'string', 'max:255'],
            'batch_number'        => ['nullable', 'string', 'max:100'],
            'expiry_date'         => ['nullable', 'date'], 
            'mode_administration' => ['required', 'string', 'max:100'],
            'cost'                => ['nullable', 'numeric', 'min:0'],
            'veterinary_name'     => ['nullable', 'string', 'max:255'],
            'observations'        => ['nullable', 'string'],
        ];
    }
}