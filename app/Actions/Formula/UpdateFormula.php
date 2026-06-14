<?php

namespace App\Actions\Formula;

use App\Models\Formula;
use Illuminate\Support\Facades\DB;

/**
 * Action : Mise à jour d'une formule nutritionnelle.
 *
 * Reconstruction complète des items (delete + recreate) pour
 * garantir la cohérence. Même format que CreateFormula.
 */
class UpdateFormula
{
    /**
     * @param  Formula $formula  La formule à modifier
     * @param  array   $data     Données validées depuis UpdateFormulaRequest
     * @return Formula La formule mise à jour
     */
    public function execute(Formula $formula, array $data): Formula
    {
        return DB::transaction(function () use ($formula, $data) {
            $batchWeight = (float) ($data['total_batch_weight'] ?? $formula->total_batch_weight ?? 1000);

            $formula->update([
                'name'               => $data['name'],
                'target_type'        => $data['target_type'],
                'species_id'         => $data['species_id'] ?? $formula->species_id,
                'production_type_id' => $data['production_type_id'] ?? $formula->production_type_id,
                'total_batch_weight' => $batchWeight,
                'instructions'       => $data['instructions'] ?? $formula->instructions,
            ]);

            // Reconstruction atomique des items
            $formula->items()->delete();

            foreach ($data['ingredients'] as $ingredient) {
                $percentage = (float) $ingredient['percentage'];
                if ($percentage <= 0) continue;

                $formula->items()->create([
                    'raw_material_id' => $ingredient['id'],
                    'percentage'      => round($percentage, 4),
                    'quantity_kg'     => round(($percentage / 100) * $batchWeight, 3),
                ]);
            }

            return $formula->fresh()->load('items.rawMaterial');
        });
    }
}
