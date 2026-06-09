<?php

namespace Database\Factories;

use App\Models\RawMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

class RawMaterialFactory extends Factory
{
    protected $model = RawMaterial::class;

    public function definition(): array
    {
        return [
            'name'            => fake()->unique()->randomElement(['Maïs', 'Soja', 'Farine de poisson', 'Son de blé', 'Tourteau', 'Coquillage', 'Prémix', 'Calcaire']),
            'unit'            => 'kg',
            'stock_qty'       => fake()->randomFloat(1, 50, 2000),
            'unit_cost'       => fake()->numberBetween(1000, 15000),
            'alert_threshold' => fake()->numberBetween(20, 100),
            'is_active'       => true,
            'energy_kcal'     => fake()->numberBetween(1500, 4000),
            'protein_rate'    => fake()->randomFloat(1, 5, 50),
            'lysine_rate'     => fake()->randomFloat(2, 0.1, 3),
            'calcium_rate'    => fake()->randomFloat(2, 0.1, 10),
        ];
    }
}
