<?php

namespace App\Actions\Formula;

use App\Models\Formula;
use Illuminate\Support\Facades\DB;

/**
 * Action : Création d'une formule nutritionnelle.
 *
 * Corrige P-03 : utilise le même format que UpdateFormula
 * (ingredients[].id + ingredients[].percentage au lieu de materials[] + quantities[])
 *
 * Corrige P-07 : la validation est toujours en pourcentages (total = 100%).
 * Les quantités en KG sont CALCULÉES depuis le pourcentage et le poids total du lot.
 */
class CreateFormula
{
    /**
     * @param  array $data  Données validées depuis StoreFormulaRequest
     * @return Formula La formule créée avec ses items
     */
    public function execute(array $data): Formula
    {
        return DB::transaction(function () use ($data) {
            $batchWeight = (float) ($data['total_batch_weight'] ?? 1000);

            $formula = Formula::create([
                'name'               => $data['name'],
                'code'               => strtoupper($data['code']),
                'target_type'        => $data['target_type'],
                'species_id'         => $data['species_id'] ?? null,
                'production_type_id' => $data['production_type_id'] ?? null,
                'poultry_type'       => $data['poultry_type'] ?? 'Chair',
                'total_batch_weight' => $batchWeight,
                'instructions'       => $data['instructions'] ?? null,
                'is_active'          => true,
                'is_locked'          => false,
            ]);

            foreach ($data['ingredients'] as $ingredient) {
                $percentage = (float) $ingredient['percentage'];
                if ($percentage <= 0) continue;

                $formula->items()->create([
                    'raw_material_id' => $ingredient['id'],
                    'percentage'      => round($percentage, 4),
                    'quantity_kg'     => round(($percentage / 100) * $batchWeight, 3),
                ]);
            }

            return $formula->load('items.rawMaterial');
        });
    }
}
