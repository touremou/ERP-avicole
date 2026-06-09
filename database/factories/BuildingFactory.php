<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'name'     => 'Bâtiment ' . fake()->unique()->numberBetween(1, 50),
            'type'     => fake()->randomElement(['chair', 'ponte', 'reproducteur', 'mixte']),
            'capacity' => fake()->randomElement([2000, 3000, 5000, 10000]),
            'surface'  => fake()->numberBetween(100, 500),
            'status'   => 'Disponible',
        ];
    }
}
