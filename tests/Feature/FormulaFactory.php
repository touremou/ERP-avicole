<?php

namespace Database\Factories;

use App\Models\Formula;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormulaFactory extends Factory
{
    protected $model = Formula::class;

    public function definition(): array
    {
        return [
            'code'        => 'FRM-' . fake()->unique()->numerify('####'),
            'name'        => fake()->unique()->randomElement(['Chair Démarrage', 'Chair Croissance', 'Chair Finition', 'Ponte Démarrage', 'Ponte Production']),
            'target_type' => fake()->randomElement(['chair', 'ponte', 'reproducteur']),
            'is_active'   => true,
        ];
    }
}
