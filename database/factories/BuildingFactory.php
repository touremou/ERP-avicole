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
            // Type DÉTERMINISTE : tiré au hasard, il rendait des tests
            // aléatoirement faux dès qu'une règle de compatibilité bâtiment/
            // type s'applique (un lot de chair dans un bâtiment « ponte »).
            // Un test qui veut un type précis le fixe lui-même.
            'type'     => 'mixte',
            'capacity' => fake()->randomElement([2000, 3000, 5000, 10000]),
            'surface'  => fake()->numberBetween(100, 500),
            'status'   => 'Disponible',
        ];
    }
}
