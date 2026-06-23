<?php

namespace Database\Seeders;

use App\Models\CropRecipe;
use Illuminate\Database\Seeder;

/**
 * Recettes de transformation agro-alimentaire de référence (PHASE 2).
 *
 * Recettes indicatives pour les filières de transformation courantes en Guinée
 * (Conakry), inspirées des pratiques IRAG / FAO / DNPIA. Idempotent : updateOrCreate
 * sur le nom + farm_id null, items remplacés à chaque passage.
 *
 * AVERTISSEMENT : ces recettes sont INDICATIVES et doivent être adaptées aux
 * équipements, variétés et conditions de transformation locales.
 */
class CropRecipeSeeder extends Seeder
{
    public function run(): void
    {
        $note_ref = 'Recette indicative (IRAG/FAO/DNPIA) — référentiel global, à adapter aux équipements et variétés locaux.';

        $recipes = [
            [
                'name'        => 'Farine de maïs blanche',
                'type'        => 'mouture',
                'output'      => 'Farine de maïs',
                'output_unit' => 'kg',
                'yield_pct'   => 72,
                'shelf_life'  => 90,
                'notes'       => 'Sécher le maïs à 14% d\'humidité avant mouture. Tamiser à 500 µm.',
                'items' => [
                    ['input_product' => 'Maïs grain sec', 'quantity' => 100, 'unit' => 'kg',  'notes' => null],
                    ['input_product' => 'Eau de trempage', 'quantity' => 10, 'unit' => 'L',   'notes' => null],
                ],
            ],
            [
                'name'        => 'Farine de riz étuvé',
                'type'        => 'mouture',
                'output'      => 'Farine de riz',
                'output_unit' => 'kg',
                'yield_pct'   => 68,
                'shelf_life'  => 180,
                'notes'       => 'Étuvage 30 min vapeur avant décorticage puis mouture.',
                'items' => [
                    ['input_product' => 'Riz paddy',          'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Eau (pour étuvage)', 'quantity' => 15,  'unit' => 'L',  'notes' => null],
                ],
            ],
            [
                'name'        => 'Huile de palme rouge',
                'type'        => 'jus',
                'output'      => 'Huile de palme rouge',
                'output_unit' => 'L',
                'yield_pct'   => 22,
                'shelf_life'  => 365,
                'notes'       => 'Stérilisation à vapeur 60 min. Presser chaud. Décanter 24 h.',
                'items' => [
                    ['input_product' => 'Régimes de palme mûrs', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Eau chaude',             'quantity' => 20,  'unit' => 'L',  'notes' => null],
                ],
            ],
            [
                'name'        => 'Arachides grillées décortiquées',
                'type'        => 'torrefaction',
                'output'      => 'Arachides grillées',
                'output_unit' => 'kg',
                'yield_pct'   => 88,
                'shelf_life'  => 30,
                'notes'       => 'Torréfaction à 180°C pendant 20-25 min. Refroidir avant décorticage.',
                'items' => [
                    ['input_product' => 'Arachides en coques', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Pâte d\'arachide (beurre de cacahuète)',
                'type'        => 'mouture',
                'output'      => 'Pâte d\'arachide',
                'output_unit' => 'kg',
                'yield_pct'   => 92,
                'shelf_life'  => 60,
                'notes'       => 'Moudre chaud. Conditionner hermétiquement à l\'abri de l\'air.',
                'items' => [
                    ['input_product' => 'Arachides grillées décortiquées', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Sel',                              'quantity' => 0.5, 'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Jus de gingembre concentré',
                'type'        => 'jus',
                'output'      => 'Jus de gingembre',
                'output_unit' => 'L',
                'yield_pct'   => 38,
                'shelf_life'  => 7,
                'notes'       => 'Peler, broyer, presser. Pasteuriser 65°C/30 min pour allonger la conservation.',
                'items' => [
                    ['input_product' => 'Gingembre frais', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Citron',          'quantity' => 5,   'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Eau',             'quantity' => 10,  'unit' => 'L',  'notes' => null],
                ],
            ],
            [
                'name'        => 'Gingembre séché en poudre',
                'type'        => 'sechage',
                'output'      => 'Poudre de gingembre',
                'output_unit' => 'kg',
                'yield_pct'   => 18,
                'shelf_life'  => 365,
                'notes'       => 'Sécher à 50-60°C pendant 6-8 h. Humidité finale < 8%. Broyer finement.',
                'items' => [
                    ['input_product' => 'Gingembre frais', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Tomate concentrée (double concentré)',
                'type'        => 'conserverie',
                'output'      => 'Concentré de tomate',
                'output_unit' => 'kg',
                'yield_pct'   => 14,
                'shelf_life'  => 180,
                'notes'       => 'Blanchir, mixer, passer tamis. Cuire à feu doux jusqu\'à réduction de 85%.',
                'items' => [
                    ['input_product' => 'Tomates fraîches mûres', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Sel',                     'quantity' => 1,   'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Farine de manioc (cossette)',
                'type'        => 'sechage',
                'output'      => 'Farine de manioc',
                'output_unit' => 'kg',
                'yield_pct'   => 32,
                'shelf_life'  => 90,
                'notes'       => 'Peler, râper, presser (essorer), sécher 48-72 h au soleil ou 6 h en séchoir. Moudre.',
                'items' => [
                    ['input_product' => 'Manioc frais', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Attiéké (semoule de manioc fermentée)',
                'type'        => 'fermentation',
                'output'      => 'Attiéké',
                'output_unit' => 'kg',
                'yield_pct'   => 40,
                'shelf_life'  => 3,
                'notes'       => 'Peler, râper, presser. Ajouter levain, fermenter 24-36 h. Cuire à la vapeur 30 min.',
                'items' => [
                    ['input_product' => 'Manioc frais',     'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Levain attiéké',   'quantity' => 2,   'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Jus de mangue pasteurisé',
                'type'        => 'jus',
                'output'      => 'Jus de mangue',
                'output_unit' => 'L',
                'yield_pct'   => 55,
                'shelf_life'  => 5,
                'notes'       => 'Peler, dénoyauter, mixer. Pasteuriser 85°C/15 min. Conditionner chaud en bouteilles.',
                'items' => [
                    ['input_product' => 'Mangues mûres',   'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Sucre',           'quantity' => 8,   'unit' => 'kg', 'notes' => null],
                    ['input_product' => 'Acide citrique',  'quantity' => 0.2, 'unit' => 'kg', 'notes' => null],
                ],
            ],
            [
                'name'        => 'Oignons séchés en rondelles',
                'type'        => 'sechage',
                'output'      => 'Oignons séchés',
                'output_unit' => 'kg',
                'yield_pct'   => 12,
                'shelf_life'  => 365,
                'notes'       => 'Éplucher, couper en rondelles 3 mm. Sécher à 55°C pendant 10-12 h. Humidité < 5%.',
                'items' => [
                    ['input_product' => 'Oignons frais', 'quantity' => 100, 'unit' => 'kg', 'notes' => null],
                ],
            ],
        ];

        CropRecipe::withoutEvents(function () use ($recipes, $note_ref) {
            foreach ($recipes as $r) {
                $recipe = CropRecipe::withoutGlobalScopes()->updateOrCreate(
                    ['name' => $r['name'], 'farm_id' => null],
                    [
                        'farm_id'                => null,
                        'transformation_type'    => $r['type'],
                        'output_product'         => $r['output'],
                        'output_unit'            => $r['output_unit'],
                        'expected_yield_percent' => $r['yield_pct'],
                        'shelf_life_days'        => $r['shelf_life'] ?? null,
                        'estimated_cost'         => null,
                        'is_active'              => true,
                        'notes'                  => $r['notes'] ?? null,
                    ]
                );

                // Remplacement intégral des items (idempotence).
                $recipe->items()->delete();
                foreach ($r['items'] as $item) {
                    $recipe->items()->create($item);
                }
            }
        });
    }
}
