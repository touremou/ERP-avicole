<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        // On vérifie la permission M (Modification)
        return Gate::allows('clients.modify');
    }

    public function rules(): array
    {
        // On récupère l'ID du client depuis l'URL (Route Model Binding)
        $clientId = $this->route('client')->id ?? $this->route('client');

        return [
            'name'         => ['required', 'string', 'max:255', Rule::unique('clients', 'name')->ignore($clientId)],
            'type'         => ['required', 'in:particulier,entreprise'],
            'category'     => ['required', 'in:grossiste,detaillant,hotel_restaurant,revendeur,autre'],
            'price_list_id' => ['nullable', 'exists:sale_price_lists,id'],
            'phone'        => ['nullable', 'string', 'max:30', Rule::unique('clients', 'phone')->ignore($clientId)],
            'email'        => ['nullable', 'email', 'max:255', Rule::unique('clients', 'email')->ignore($clientId)],
            'address'      => ['nullable', 'string', 'max:1000'],
            'nif'          => ['nullable', 'string', 'max:50', 'prohibited_if:type,particulier'],
            'rccm'         => ['nullable', 'string', 'max:50', 'prohibited_if:type,particulier'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'status'       => ['required', 'in:actif,suspendu,blackliste'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Un client avec ce nom ou cette raison sociale existe déjà.',
            'phone.unique' => 'Ce numéro de téléphone est déjà attribué à un autre client.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'nif.prohibited_if' => 'Un particulier ne peut pas avoir de NIF.',
            'rccm.prohibited_if' => 'Un particulier ne peut pas avoir de RCCM.',
        ];
    }
}