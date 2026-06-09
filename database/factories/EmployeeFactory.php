<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employee_id'    => 'EMP-' . fake()->unique()->numerify('####'),
            'first_name'     => fake()->firstName(),
            'last_name'      => fake()->lastName(),
            'gender'         => fake()->randomElement(['M', 'F']),
            'phone'          => fake()->numerify('+224 6## ## ## ##'),
            'email'          => fake()->unique()->safeEmail(),
            'job_title'      => fake()->randomElement(['Technicien', 'Ouvrier', 'Responsable']),
            'department'     => fake()->randomElement(['Élevage', 'Provenderie', 'Administration']),
            'contract_type'  => fake()->randomElement(['CDI', 'CDD']),
            'hire_date'      => fake()->dateTimeBetween('-2 years', 'now'),
            'salary'         => fake()->numberBetween(1500000, 5000000),
            'status'         => 'Actif',
        ];
    }
}
