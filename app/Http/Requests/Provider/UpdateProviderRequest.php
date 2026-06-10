<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('annuaire.M');
    }

    public function rules(): array
    {
        $providerId = $this->route('provider')->id ?? $this->route('provider');

        return [
            'name'          => 'required|string|max:255|unique:providers,name,' . $providerId,
            'phone'         => 'required|string|max:20',
            'type'          => 'required|in:Poussins,Aliment,Santé,Matériel,Services,Autre',
            'domain'        => 'nullable|string|max:255',
            'email'         => 'nullable|email',
            'address'       => 'nullable|string',
            'rccm'          => 'nullable|string|max:100',
            'nif'           => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'reliability'   => 'required|in:Bon,Moyen,Mauvais',
            'status'        => 'nullable|in:Actif,Blacklisté,Inactif',
        ];
    }
}