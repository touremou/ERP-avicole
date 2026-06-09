<?php

namespace App\Http\Requests\Incubation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RecordMirageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('M');
    }

    public function rules(): array
    {
        $incubation = $this->route('incubation'); 
        $maxEggs = $incubation ? $incubation->eggs_count : 0;

        return [
            'fertile_eggs' => ['required', 'integer', 'min:0', "max:{$maxEggs}"],
        ];
    }

    public function messages(): array
    {
        return [
            'fertile_eggs.max' => "Le nombre d'œufs fertiles ne peut excéder le total incubé.",
        ];
    }
}

/*
namespace App\Http\Requests\Incubation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RecordMirageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('M'); // Nécessite les droits de modification
    }

    public function rules(): array
    {
        $incubation = $this->route('incubation'); // Récupère l'incubation depuis l'URL
        $maxEggs = $incubation ? $incubation->eggs_count : 0;

        return [
            // Les œufs fertiles ne peuvent pas être supérieurs au nombre d'œufs mis en machine
            'fertile_eggs' => ['required', 'integer', 'min:0', "max:{$maxEggs}"],
        ];
    }

    public function messages(): array
    {
        return [
            'fertile_eggs.max' => "Le nombre d'œufs fertiles ne peut excéder le total incubé ({$this->route('incubation')->eggs_count}).",
        ];
    }
}
    */