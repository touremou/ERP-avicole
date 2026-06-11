<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('annuaire.C');
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255|unique:providers,name',
            'logo'          => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'phone'         => 'required|string|max:20',
            'type'          => 'required|in:Poussins,Aliment,Santé,Matériel,Services,Autre',
            'domain'        => 'nullable|string|max:255',
            'email'         => 'nullable|email',
            'address'       => 'nullable|string',
            'rccm'          => 'nullable|string|max:100',
            'nif'           => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'reliability'   => 'nullable|in:Bon,Moyen,Mauvais',
        ];
    }
}