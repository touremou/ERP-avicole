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
            'batch_id'             => ['required', 'integer', \App\Support\Ferme::existe('batches')],
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

            // Même définition que l'écran, la tournée et l'action commune : le
            // type de production. Une liste de types écrite ici à la main
            // laissait passer un bélier « reproducteur ».
            if (! $batch->tracksEggs()) {
                $validator->errors()->add('batch_id', "Seuls les lots de ponte peuvent enregistrer des collectes.");
            }

            // Garde-fou zootechnique : un lot trop jeune n'est pas en âge de
            // pondre (phase démarrage/croissance/pré-ponte). Collecter des œufs
            // avant la maturité sexuelle est une aberration.
            $minAge = $batch->minLayingAgeDays();
            if ($batch->age < $minAge) {
                $minWeeks = (int) ceil($minAge / 7);
                $ageWeeks = (int) floor($batch->age / 7);
                $validator->errors()->add(
                    'batch_id',
                    "Lot trop jeune pour la ponte : {$batch->age} jours (~{$ageWeeks} sem.), "
                    . "phase « {$batch->current_phase} ». L'entrée en ponte est attendue vers "
                    . "{$minAge} jours (~{$minWeeks} sem.). Vérifiez le lot ou la date d'arrivée."
                );
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

            // Le plafond de 100 % n'est plus recopié ici : il comptait sur
            // TOUS les sujets du lot, coqs compris, quand l'action — porte de
            // toutes les saisies — compte sur les pondeuses
            // (Batch::effectifPondeur). Deux dénominateurs, deux taux annoncés
            // pour la même collecte ; seul celui de l'action reste.
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