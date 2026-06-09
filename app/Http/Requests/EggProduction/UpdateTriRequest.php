<?php

namespace App\Http\Requests\EggProduction;

use App\Models\EggProduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation du tri + vérification de balance.
 * Total trié (calibres + cassés + petits) doit égaler total collecté.
 */
class UpdateTriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('production.M');
    }

    public function rules(): array
    {
        return [
            'broken_eggs'  => 'required|integer|min:0',
            'small_eggs'   => 'required|integer|min:0',
            'grade_xl_alv' => 'nullable|integer|min:0',
            'grade_xl_uni' => 'nullable|integer|min:0|max:' . (setting('general.eggs_per_tray', 30) - 1),
            'grade_l_alv'  => 'nullable|integer|min:0',
            'grade_l_uni'  => 'nullable|integer|min:0|max:' . (setting('general.eggs_per_tray', 30) - 1),
            'grade_m_alv'  => 'nullable|integer|min:0',
            'grade_m_uni'  => 'nullable|integer|min:0|max:' . (setting('general.eggs_per_tray', 30) - 1),
            'grade_s_alv'  => 'nullable|integer|min:0',
            'grade_s_uni'  => 'nullable|integer|min:0|max:' . (setting('general.eggs_per_tray', 30) - 1),
        ];
    }

    /**
     * Vérifie que le total trié correspond exactement au total collecté.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Récupérer la production depuis la route
            $prod = $this->route('eggProduction')
                ?? EggProduction::find($this->route('egg_production'));

            if (! $prod) return;

            $totalTriUnites = 0;
            foreach (['xl', 'l', 'm', 's'] as $g) {
                $totalTriUnites += ((int) $this->input("grade_{$g}_alv", 0) * setting('general.eggs_per_tray', 30))
                                 + (int) $this->input("grade_{$g}_uni", 0);
            }
            $totalTriUnites += (int) $this->broken_eggs + (int) $this->small_eggs;

            if (abs($prod->total_eggs_collected - $totalTriUnites) > 0.1) {
                $validator->errors()->add(
                    'logic',
                    "Balance incorrecte : trié = {$totalTriUnites}, collecté = {$prod->total_eggs_collected}. "
                    . "Écart : " . abs($prod->total_eggs_collected - $totalTriUnites) . " œuf(s)."
                );
            }
        });
    }
}
