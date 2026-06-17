<?php

namespace App\Http\Requests\EggProduction;

use App\Rules\AfterBatchArrival;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreEggProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production.C');
    }

    public function rules(): array
    {
        return [
            'batch_id'             => 'required|integer|exists:batches,id',
            'production_date'      => ['required', 'date', 'before_or_equal:today', new AfterBatchArrival],
            'total_eggs_collected' => 'required|integer|min:0',
            'broken_eggs'          => 'nullable|integer|min:0',
            'small_eggs'           => 'nullable|integer|min:0',
            'observations'         => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $batch = \App\Models\Batch::find($this->batch_id);

            if (! $batch) return;

            if ($batch->status !== 'Actif') {
                $validator->errors()->add('batch_id', "Le lot {$batch->code} est clôturé.");
            }

            if (! in_array($batch->type, ['ponte', 'repro', 'reproducteur'])) {
                $validator->errors()->add('batch_id', "Seuls les lots de ponte peuvent enregistrer des collectes.");
            }

            // ❌ LE BLOC DE VÉRIFICATION DES DOUBLONS A ÉTÉ SUPPRIMÉ ICI ❌
            // Les techniciens peuvent désormais soumettre autant de collectes que nécessaire dans la même journée.

            // Cohérence : cassés + petits ne peuvent pas dépasser le total
            $broken = (int) ($this->broken_eggs ?? 0);
            $small  = (int) ($this->small_eggs ?? 0);
            $total  = (int) ($this->total_eggs_collected ?? 0);

            if (($broken + $small) > $total) {
                $validator->errors()->add('broken_eggs', "Cassés + petits ({$broken} + {$small}) > total collecté ({$total}).");
            }

            // Taux de ponte > 100 % = biologiquement impossible (1 œuf/sujet/jour max)
            if ($total > 0 && $batch->current_quantity > 0) {
                $existing = \App\Models\EggProduction::where('batch_id', $batch->id)
                    ->where('production_date', $this->production_date)
                    ->first();
                $existingTotal   = $existing ? (int) $existing->total_eggs_collected : 0;
                $projectedTotal  = $existingTotal + $total;

                if ($projectedTotal > $batch->current_quantity) {
                    $rate = number_format(($projectedTotal / $batch->current_quantity) * 100, 1);
                    $validator->errors()->add(
                        'total_eggs_collected',
                        "Taux de ponte impossible : {$projectedTotal} œufs pour {$batch->current_quantity} sujets = {$rate} %. "
                        . "Le maximum biologique est 100 % (1 œuf/sujet/jour). "
                        . "Vérifiez votre saisie ; si vous saisissez un cumul multi-jours, fractionnez sur les dates réelles."
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'batch_id.exists'                 => 'Lot introuvable.',
            'production_date.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'total_eggs_collected.min'        => 'Le nombre d\'œufs collectés ne peut pas être négatif.',
        ];
    }
}