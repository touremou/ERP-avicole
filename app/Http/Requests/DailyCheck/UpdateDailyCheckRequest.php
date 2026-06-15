<?php

namespace App\Http\Requests\DailyCheck;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation pour la rectification d'un pointage journalier.
 *
 * Mutualise les règles de champ et les bornes des métriques d'extension
 * (ruminants & aquaculture) avec StoreDailyCheckRequest, afin que la
 * rectification soit aussi stricte que la saisie (un pH > 14, une survie
 * > 100 %, etc. sont rejetés dans les deux sens).
 *
 * Les vérifications métier dépendant des anciennes valeurs du pointage
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
            'temp_min'           => 'nullable|numeric',
            'temp_max'           => 'nullable|numeric',
            'humidity'           => 'nullable|numeric|min:0|max:100',
            'avg_weight'         => 'nullable|numeric|min:0',
            'qty_quarantine_in'  => 'required|integer|min:0',
            'qty_quarantine_out' => 'required|integer|min:0',
            'qty_sorted_out'     => 'nullable|integer|min:0',
            'treatment_type'     => 'nullable|string|max:255',
            'treatment_name'     => 'nullable|string|max:255',
            'observations'       => 'nullable|string|max:2000',
            'litter_changed'     => 'nullable|boolean',
            'manure_collected_kg' => 'nullable|numeric|min:0|max:100000',
        ] + StoreDailyCheckRequest::extensionRules();
    }

    public function messages(): array
    {
        return [
            'mortality.min'     => 'La mortalité ne peut pas être négative.',
            'feed_consumed.min' => 'La consommation ne peut pas être négative.',
            'humidity.max'      => 'L\'humidité ne peut pas dépasser 100%.',
        ];
    }

    /**
     * Défauts alignés sur la saisie : un champ effectif laissé vide vaut 0
     * (et non une erreur « requis »), comme dans StoreDailyCheckRequest.
     */
    protected function prepareForValidation(): void
    {
        $litterChanged = $this->has('litter_changed') ? 1 : 0;

        $this->merge([
            'litter_changed'     => $litterChanged,
            'qty_quarantine_in'  => $this->input('qty_quarantine_in', 0) ?: 0,
            'qty_quarantine_out' => $this->input('qty_quarantine_out', 0) ?: 0,
            'qty_sorted_out'     => $this->input('qty_sorted_out', 0) ?: 0,
            // Cohérence : pas de fumier comptabilisé sans renouvellement de litière.
            'manure_collected_kg' => $litterChanged ? $this->input('manure_collected_kg') : 0,
        ]);
    }
}
