<?php

namespace Database\Factories;

use App\Models\MillMachine;
use Illuminate\Database\Eloquent\Factories\Factory;

class MillMachineFactory extends Factory
{
    protected $model = MillMachine::class;

    public function definition(): array
    {
        return [
            'name'                       => 'Broyeur ' . fake()->unique()->numberBetween(1, 20),
            'status'                     => 'Opérationnel',
            'capacity_per_hour'          => fake()->randomElement([200, 500, 1000]),
            'total_hours_run'            => fake()->numberBetween(0, 500),
            'maintenance_interval_hours' => 200,
        ];
    }
}
