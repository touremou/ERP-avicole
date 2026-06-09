<?php

namespace Database\Factories;

use App\Models\Formula;
use App\Models\MillMachine;
use App\Models\MillProduction;
use Illuminate\Database\Eloquent\Factories\Factory;

class MillProductionFactory extends Factory
{
    protected $model = MillProduction::class;

    public function definition(): array
    {
        return [
            'batch_number'      => 'OP-' . fake()->unique()->numerify('########'),
            'formula_id'        => Formula::factory(),
            'machine_id'        => MillMachine::factory(),
            'quantity_produced' => fake()->randomElement([250, 500, 1000, 1500]),
            'real_cost_per_kg'  => fake()->numberBetween(3000, 8000),
            'status'            => 'Planifié',
            'operator_id'       => 1,
        ];
    }
}
