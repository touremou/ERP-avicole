<?php

namespace App\Http\Requests\DailyCheck;

use App\Models\Batch;
use App\Models\Stock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation pour la création d'un pointage journalier.
 *
 * Intègre la vérification de stock aliment qui était dans le controller.
 */
class StoreDailyCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('C');
    }

    public function rules(): array
    {
        return [
            'batch_id'          => 'required|integer|exists:batches,id',
            'check_date'        => 'required|date|before_or_equal:today',
            'mortality'         => 'required|integer|min:0',
            'feed_consumed'     => 'required|numeric|min:0',
            'feed_type'         => 'required|string|max:255',
            'water_consumed'    => 'nullable|numeric|min:0',
            'temp_min'          => 'nullable|numeric',
            'temp_max'          => 'nullable|numeric',
            'humidity'          => 'nullable|numeric|min:0|max:100',
            'avg_weight'        => 'nullable|numeric|min:0',
            'qty_quarantine_in' => 'nullable|integer|min:0',
            'qty_quarantine_out'=> 'nullable|integer|min:0',
            'qty_sorted_out'    => 'nullable|integer|min:0',
            'treatment_type'    => 'nullable|string|max:255',
            'treatment_name'    => 'nullable|string|max:255',
            'observations'      => 'nullable|string|max:2000',
            'litter_changed'    => 'nullable|boolean',
        ];
    }

    /**
     * Validations métier post-règles.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $batch = Batch::find($this->input('batch_id'));
            if (! $batch) {
                return;
            }

            // Lot doit être actif
            if ($batch->status !== 'Actif') {
                $validator->errors()->add('batch_id', 'Ce lot est clôturé. Saisie impossible.');
                return;
            }

            // Mortalité ne peut pas dépasser l'effectif
            $totalImpact = (int) $this->input('mortality', 0)
                         + (int) $this->input('qty_quarantine_in', 0)
                         + (int) $this->input('qty_sorted_out', 0)
                         - (int) $this->input('qty_quarantine_out', 0);

            if ($totalImpact > $batch->current_quantity) {
                $validator->errors()->add(
                    'mortality',
                    "Impact total ({$totalImpact}) dépasse l'effectif vivant ({$batch->current_quantity})."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'batch_id.exists'              => 'Lot introuvable.',
            'check_date.before_or_equal'   => 'La date ne peut pas être dans le futur.',
            'mortality.min'                => 'La mortalité ne peut pas être négative.',
            'feed_consumed.min'            => 'La consommation ne peut pas être négative.',
            'humidity.max'                 => 'L\'humidité ne peut pas dépasser 100%.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'litter_changed' => $this->has('litter_changed') ? 1 : 0,
            'qty_quarantine_in'  => $this->input('qty_quarantine_in', 0),
            'qty_quarantine_out' => $this->input('qty_quarantine_out', 0),
            'qty_sorted_out'     => $this->input('qty_sorted_out', 0),
        ]);
    }
}
