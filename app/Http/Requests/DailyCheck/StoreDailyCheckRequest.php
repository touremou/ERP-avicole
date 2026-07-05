<?php

namespace App\Http\Requests\DailyCheck;

use App\Models\Batch;
use App\Models\Stock;
use App\Rules\AfterBatchArrival;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation pour la crÃ©ation d'un pointage journalier.
 *
 * IntÃ¨gre la vÃ©rification de stock aliment qui Ã©tait dans le controller.
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
            // Bornes physiques strictes (anti « fat-finger » : 220 au lieu de
            // 22.0 sur tablette gantée) — un bâtiment d'élevage vit entre
            // −10 et +50 °C, au-delà c'est une erreur de saisie.
            'temp_min'          => 'nullable|numeric|between:-10,50',
            'temp_max'          => 'nullable|numeric|between:-10,50|gte:temp_min',
            'temp_source'       => 'nullable|in:manuel,iot',
            'temp_recorded_by'  => 'nullable|string|max:100',
            'humidity'          => 'nullable|numeric|min:0|max:100',
            'avg_weight'        => 'nullable|numeric|min:0',
            // uniformity_pct N'EST PLUS accepté du client (source d'erreur) :
            // il est CALCULÉ côté serveur depuis les pesées d'échantillon
            // (DailyCheck::computeSampleStats) — seule source de vérité.
            // Pesées individuelles (kg) : 0,001–200 kg, du poussin au bovin
            // (borne anti-erreur d'unité).
            'weight_samples'    => 'nullable|array|max:500',
            'weight_samples.*'  => 'numeric|min:0.001|max:200',
            'qty_quarantine_in' => 'nullable|integer|min:0',
            'qty_quarantine_out'=> 'nullable|integer|min:0',
            // Morts PARMI les isolés (déjà hors effectif : pas de double
            // décompte) — le solde disponible est contrôlé dans l'Action.
            'mortality_infirmary' => 'nullable|integer|min:0',
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
     * RÃ¨gles des mÃ©triques d'extension espÃ¨ce-spÃ©cifiques (ruminants & aquaculture).
     *
     * BornÃ©es pour fiabiliser alertes & rapports : un pH > 14, une survie
     * > 100 % ou une biomasse nÃ©gative n'ont aucun sens. Toutes nullable
     * (absentes pour la volaille â†’ sans impact). PartagÃ©es entre crÃ©ation
     * et mise Ã  jour du pointage.
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
     * Validations mÃ©tier post-rÃ¨gles.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $batch = Batch::find($this->input('batch_id'));
            if (! $batch) {
                return;
            }

            // Lot doit Ãªtre actif
            if ($batch->status !== 'Actif') {
                $validator->errors()->add('batch_id', 'Ce lot est clÃ´turÃ©. Saisie impossible.');
                return;
            }

            // MortalitÃ© ne peut pas dÃ©passer l'effectif
            $totalImpact = (int) $this->input('mortality', 0)
                         + (int) $this->input('qty_quarantine_in', 0)
                         + (int) $this->input('qty_sorted_out', 0)
                         - (int) $this->input('qty_quarantine_out', 0);

            if ($totalImpact > $batch->current_quantity) {
                $validator->errors()->add(
                    'mortality',
                    "Impact total ({$totalImpact}) dÃ©passe l'effectif vivant ({$batch->current_quantity})."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'batch_id.exists'              => 'Lot introuvable.',
            'check_date.before_or_equal'   => 'La date ne peut pas Ãªtre dans le futur.',
            'mortality.min'                => 'La mortalitÃ© ne peut pas Ãªtre nÃ©gative.',
            'feed_consumed.min'            => 'La consommation ne peut pas Ãªtre nÃ©gative.',
            'humidity.max'                 => 'L\'humiditÃ© ne peut pas dÃ©passer 100%.',
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
            // Le fumier n'est ramassÃ© que lors d'un renouvellement de litiÃ¨re :
            // toute quantitÃ© saisie sans litiÃ¨re changÃ©e est ignorÃ©e.
            'manure_collected_kg' => $litterChanged ? $this->input('manure_collected_kg') : 0,
        ]);
    }
}
