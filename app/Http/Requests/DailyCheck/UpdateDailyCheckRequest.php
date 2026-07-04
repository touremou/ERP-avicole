<?php

namespace App\Http\Requests\DailyCheck;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation pour la rectification d'un pointage journalier.
 *
 * Mutualise les rÃ¨gles de champ et les bornes des mÃ©triques d'extension
 * (ruminants & aquaculture) avec StoreDailyCheckRequest, afin que la
 * rectification soit aussi stricte que la saisie (un pH > 14, une survie
 * > 100 %, etc. sont rejetÃ©s dans les deux sens).
 *
 * Les vÃ©rifications mÃ©tier dÃ©pendant des anciennes valeurs du pointage
 * (delta d'effectif, compensation de stock aliment) restent dans le
 * controller : elles ont besoin de l'instance existante.
 */
class UpdateDailyCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('elevage.M');
    }

    public function rules(): array
    {
        return [
            'mortality'          => 'required|integer|min:0',
            'feed_consumed'      => 'required|numeric|min:0',
            'feed_type'          => 'required|string|max:255',
            'water_consumed'     => 'nullable|numeric|min:0',
            // Bornes physiques strictes (anti « fat-finger », cf. Store).
            'temp_min'           => 'nullable|numeric|between:-10,50',
            'temp_max'           => 'nullable|numeric|between:-10,50|gte:temp_min',
            'humidity'           => 'nullable|numeric|min:0|max:100',
            'avg_weight'         => 'nullable|numeric|min:0',
            'uniformity_pct'    => 'nullable|numeric|min:0|max:100',
            'qty_quarantine_in'  => 'required|integer|min:0',
            'qty_quarantine_out' => 'required|integer|min:0',
            'qty_sorted_out'     => 'nullable|integer|min:0',
            'treatment_type'     => 'nullable|string|max:255',
            'treatment_name'     => 'nullable|string|max:255',
            'observations'       => 'nullable|string|max:2000',
            'litter_changed'     => 'nullable|boolean',
            'manure_collected_kg' => 'nullable|numeric|min:0|max:100000',
            'lame_count'           => 'nullable|integer|min:0|max:1000000',
            'pecking_injury_count' => 'nullable|integer|min:0|max:1000000',
        ] + StoreDailyCheckRequest::extensionRules();
    }

    public function messages(): array
    {
        return [
            'mortality.min'     => 'La mortalitÃ© ne peut pas Ãªtre nÃ©gative.',
            'feed_consumed.min' => 'La consommation ne peut pas Ãªtre nÃ©gative.',
            'humidity.max'      => 'L\'humiditÃ© ne peut pas dÃ©passer 100%.',
        ];
    }

    /**
     * DÃ©fauts alignÃ©s sur la saisie : un champ effectif laissÃ© vide vaut 0
     * (et non une erreur Â« requis Â»), comme dans StoreDailyCheckRequest.
     */
    protected function prepareForValidation(): void
    {
        $litterChanged = $this->has('litter_changed') ? 1 : 0;

        $this->merge([
            'litter_changed'     => $litterChanged,
            'qty_quarantine_in'  => $this->input('qty_quarantine_in', 0) ?: 0,
            'qty_quarantine_out' => $this->input('qty_quarantine_out', 0) ?: 0,
            'qty_sorted_out'     => $this->input('qty_sorted_out', 0) ?: 0,
            // CohÃ©rence : pas de fumier comptabilisÃ© sans renouvellement de litiÃ¨re.
            'manure_collected_kg' => $litterChanged ? $this->input('manure_collected_kg') : 0,
        ]);
    }
}
