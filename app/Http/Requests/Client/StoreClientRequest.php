<?php

namespace App\Http\Requests\Client; // (Ou Sale\ si vous décidez de le déplacer)

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('commerce.C');
    }

    public function rules(): array
    {
        return [
            // Ajout de l'unicité sur le nom pour éviter les doublons exacts
            'name'         => 'required|string|max:255|unique:clients,name',
            'type'         => 'required|in:particulier,entreprise',
            'category'     => 'required|in:grossiste,detaillant,hotel_restaurant,revendeur,autre',
            'price_list_id' => 'nullable|exists:sale_price_lists,id',

            // Unicité suggérée sur le téléphone et l'email (à adapter selon vos besoins métiers)
            'phone'        => 'nullable|string|max:30|unique:clients,phone',
            'email'        => 'nullable|email|max:255|unique:clients,email',
            
            'address'      => 'nullable|string|max:1000',
            
            // Le NIF et RCCM ne devraient être acceptés que si c'est une entreprise
            'nif'          => 'nullable|string|max:50|prohibited_if:type,particulier',
            'rccm'         => 'nullable|string|max:50|prohibited_if:type,particulier',
            
            'credit_limit' => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string|max:2000',
        ];
    }
    
    /**
     * Personnalisation des messages d'erreur (Optionnel mais recommandé pour l'UX)
     */
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