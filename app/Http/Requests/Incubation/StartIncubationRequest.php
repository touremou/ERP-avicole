<?php
namespace App\Http\Requests\Incubation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use App\Models\Incubator;

class StartIncubationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production.C');
    }

    public function rules(): array
    {
        $incubator = Incubator::find($this->incubator_id);
        $maxCapacity = $incubator ? $incubator->capacity : 0;

        $rules = [
            'incubator_id' => ['required', 'exists:incubators,id'],
            'start_date'   => ['required', 'date', 'before_or_equal:today'],
            'eggs_count'   => ['required', 'integer', 'min:1', "max:{$maxCapacity}"],
            'egg_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'source_type'  => ['required', 'in:internal,external'],
            'duration'     => ['nullable', 'integer', 'min:10', 'max:60'],
        ];

        // 💡 LOGIQUE CONDITIONNELLE
        if ($this->source_type === 'internal') {
            $rules['batch_id'] = ['required', 'exists:batches,id'];
        } else {
            $rules['provider_id'] = ['required'];
            
            // Si on crée un nouveau fournisseur à la volée
            if ($this->provider_id === 'new') {
                $rules['new_provider_name']  = ['required', 'string', 'max:255', 'unique:providers,name'];
                $rules['new_provider_phone'] = ['required', 'string', 'max:20'];
                $rules['new_provider_type']  = ['required', 'in:Poussins,Aliment,Santé,Matériel,Autre'];
            } else {
                // Sinon, c'est un ID existant
                $rules['provider_id'][] = 'exists:providers,id';
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'eggs_count.max' => "Le nombre d'œufs dépasse la capacité de la machine (Max: :max).",
        ];
    }
}

/*
namespace App\Http\Requests\Incubation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use App\Models\Incubator;

class StartIncubationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production.C');
    }

    public function rules(): array
    {
        // On récupère la machine pour limiter le nombre d'œufs max
        $incubator = Incubator::find($this->incubator_id);
        $maxCapacity = $incubator ? $incubator->capacity : 0;

        $rules = [
            'incubator_id' => ['required', 'exists:incubators,id'],
            'start_date'   => ['required', 'date', 'before_or_equal:today'],
            'eggs_count'   => ['required', 'integer', 'min:1', "max:{$maxCapacity}"],
            'source_type'  => ['required', 'in:internal,external'],
        ];

        // Règles conditionnelles selon la provenance des œufs
        if ($this->source_type === 'internal') {
            $rules['batch_id'] = ['required', 'exists:batches,id'];
        } else {
            $rules['external_source_name'] = ['required', 'string', 'max:100'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'eggs_count.max' => "Le nombre d'œufs dépasse la capacité de la machine (Max: :max).",
        ];
    }
}*/