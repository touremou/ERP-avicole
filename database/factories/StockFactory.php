<?php

namespace Database\Factories;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'item_name'        => fake()->unique()->word(),
            'category'         => fake()->randomElement(['oeufs', 'conso', 'litieres', 'materiels']),
            'unit'             => fake()->randomElement(['KG', 'Alvéole', 'Unité']),
            'current_quantity' => fake()->randomFloat(1, 0, 5000),
            'alert_threshold'  => fake()->randomFloat(1, 10, 100),
            'metadata'         => json_encode([]),
        ];
    }
}
