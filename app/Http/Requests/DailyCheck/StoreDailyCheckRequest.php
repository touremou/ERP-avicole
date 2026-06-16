<?php

namespace App\Http\Requests\DailyCheck;

use App\Models\Batch;
use App\Models\Stock;
use App\Rules\AfterBatchArrival;
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
        return Gate::allows('elevage.C');
    }

    public function rules(): array
    {
        return [
            'batch_id'          => 'required|integer|exists:batches,id',
            'check_date'        => ['required', 'date', 'before_or_equal:today', new AfterBatchArrival],
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
            'manure_collected_kg' => 'nullable|numeric|min:0|max:100000',
            'lame_count'           => 'nullable|integer|min:0|max:1000000',
            'pecking_injury_count' => 'nullable|integer|min:0|max:1000000',
        ] + self::extensionRules();
    }

    /**
     * Règles des métriques d'extension espèce-spécifiques (ruminants & aquaculture).
     *
     * Bornées pour fiabiliser alertes & rapports : un pH > 14, une survie
     * > 100 % ou une biomasse négative n'ont aucun sens. Toutes nullable
     * (absentes pour la volaille → sans impact). Partagées entre création
     * et mise à jour du pointage.
     */
    public static function extensionRules(): array
    {
        return [
            // Ruminants
            'ext_qty_born'          => 'nullable|integer|min:0',
            'ext_qty_weaned'        => 'nullable|integer|min:0',
            'ext_milk_liters'       => 'nullable|numeric|min:0',
            'ext_milk_fat_pct'      => 'nullable|numeric|min:0|max:100',
            // Aquaculture
            'ext_water_temp'        => 'nullable|numeric|min:0|max:50',
            'ext_water_ph'          => 'nullable|numeric|min:0|max:14',
            'ext_water_o2_ppm'      => 'nullable|numeric|min:0|max:30',
            'ext_water_ammonia_ppm' => 'nullable|numeric|min:0|max:50',
            'ext_biomass_kg'        => 'nullable|numeric|min:0',
            'ext_survival_rate'     => 'nullable|numeric|min:0|max:100',
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
        $litterChanged = $this->has('litter_changed') ? 1 : 0;

        $this->merge([
            'litter_changed' => $litterChanged,
            'qty_quarantine_in'  => $this->input('qty_quarantine_in', 0),
            'qty_quarantine_out' => $this->input('qty_quarantine_out', 0),
            'qty_sorted_out'     => $this->input('qty_sorted_out', 0),
            // Le fumier n'est ramassé que lors d'un renouvellement de litière :
            // toute quantité saisie sans litière changée est ignorée.
            'manure_collected_kg' => $litterChanged ? $this->input('manure_collected_kg') : 0,
        ]);
    }
}
