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
        /*
         * BORNÉ PAR LA PLACE RESTANTE, ET NON PAR LA CAPACITÉ TOTALE.
         *
         * C'était `$incubator->capacity` : une machine de 10 000 œufs portant déjà un
         * cycle de 8 000 acceptait une nouvelle mise à couver de 10 000. Rien ne
         * vérifiait qu'elle n'était pas déjà pleine — `StartIncubation` la marquait
         * « Occupé » sans jamais regarder si elle l'était déjà.
         *
         * POURQUOI ON N'INTERDIT PAS PUREMENT LE SECOND CYCLE, contrairement à la
         * provenderie qui refuse une OP sur une machine occupée : un mélangeur ne
         * peut pas faire deux gâchées à la fois, mais un incubateur accueille
         * couramment plusieurs mises à couver à des dates différentes
         * (incubation multi-étages). Refuser bloquerait une pratique légitime ; ce
         * qu'il faut empêcher, c'est le DÉPASSEMENT.
         */
        $incubator = Incubator::find($this->incubator_id);
        $maxCapacity = $incubator ? $incubator->remainingCapacity() : 0;

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
        $incubator = Incubator::find($this->incubator_id);

        if ($incubator) {
            $dedans = $incubator->eggsInIncubation();

            $messages = [
                'eggs_count.max' => $dedans > 0
                    ? __('Il ne reste que :reste place(s) dans cet incubateur : :dedans œuf(s) y sont déjà en incubation sur une capacité de :capacite.', [
                        'reste' => $incubator->remainingCapacity(), 'dedans' => $dedans, 'capacite' => $incubator->capacity,
                    ])
                    : __('Capacité de l’incubateur dépassée (:capacite œufs).', ['capacite' => $incubator->capacity]),
            ];
        } else {
            $messages = [];
        }

        return $messages + $this->baseMessages();
    }

    private function baseMessages(): array
    {
        return [
            'eggs_count.max' => "Le nombre d'œufs dépasse la capacité de la machine (Max: :max).",
        ];
    }
}
