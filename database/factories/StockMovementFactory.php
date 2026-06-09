<?php

namespace Database\Factories;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'stock_id' => Stock::factory(),
            'user_id'  => User::factory(),
            'type'     => fake()->randomElement(['in', 'out', 'adjustment']),
            'quantity' => fake()->randomFloat(2, 1, 500),
            'notes'    => fake()->sentence(),
        ];
    }
}
