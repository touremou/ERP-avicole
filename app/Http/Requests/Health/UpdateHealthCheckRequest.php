<?php

namespace App\Http\Requests\Health;

use App\Rules\AfterBatchArrival;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateHealthCheckRequest extends FormRequest
{
    /**
     * Détermine si l'utilisateur a le droit de modifier une intervention.
     */
    public function authorize(): bool
    {
        return Gate::allows('elevage.M'); // M = Modification
    }

    /**
     * Règles de validation pour la mise à jour.
     */
    public function rules(): array
    {
        return [
            'batch_id'            => ['required', 'exists:batches,id'],
            // On retire 'before_or_equal:today' ici pour permettre de corriger
            // la date d'une intervention très ancienne si besoin — mais jamais
            // avant l'arrivée du lot (âge négatif incohérent).
            'intervention_date'   => ['required', 'date', new AfterBatchArrival],
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