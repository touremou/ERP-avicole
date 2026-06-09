<?php

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'provider_id' => 'FRN-' . fake()->unique()->numerify('####'),
            'name'        => fake()->company() . ' ' . fake()->randomElement(['SARL', 'SA', 'Ets']),
            'type'        => fake()->randomElement(['Aliment', 'Poussins', 'Matériel', 'Santé']),
            'domain'      => fake()->randomElement(['Aviculture', 'Agroalimentaire', 'Vétérinaire']),
            'phone'       => fake()->numerify('+224 6## ## ## ##'),
            'email'       => fake()->unique()->companyEmail(),
            'address'     => fake()->address(),
            'status'      => 'Actif',
        ];
    }
}
