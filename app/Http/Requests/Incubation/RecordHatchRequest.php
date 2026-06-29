<?php

namespace App\Http\Requests\Incubation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RecordHatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production.M');
    }

    public function rules(): array
    {
        $incubation = $this->route('incubation');
        // Limite stricte : Poussins <= Œufs déclarés fertiles
        $maxChicks = $incubation ? $incubation->fertile_eggs : 0;

        return [
            'hatched_chicks' => ['required', 'integer', 'min:0', "max:{$maxChicks}"],
        ];
    }

    public function messages(): array
    {
        return [
            'hatched_chicks.max' => "Impossible. Seulement {$this->route('incubation')->fertile_eggs} œufs étaient fertiles.",
        ];
    }
}
/*
namespace App\Http\Requests\Incubation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RecordHatchRequest extends FormRequest
{
    
    public function authorize(): bool
    {
        // Seuls les profils ayant la permission de Modification (M) peuvent valider une éclosion
        return Gate::allows('production.M');
    }


     
    //Règles de validation des données de la requête.
    public function rules(): array
    {
        return [
            'hatched_chicks' => ['required', 'integer', 'min:0'],
        ];
    }

     //Messages d'erreur personnalisés (i18n ready).
    public function messages(): array
    {
        return [
            'hatched_chicks.required' => 'Le nombre de poussins éclos est obligatoire.',
            'hatched_chicks.integer'  => 'Le nombre doit être un entier valide.',
            'hatched_chicks.min'      => 'Le nombre d\'éclosions ne peut pas être négatif.',
        ];
    }
}*/