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
