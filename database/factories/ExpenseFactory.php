<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'uuid'           => (string) Str::uuid(),
            'is_synced'      => true,
            'reference'      => sprintf('DEP-%05d', $seq),
            'category'       => fake()->randomElement(array_keys(Expense::CATEGORIES)),
            'label'          => fake()->sentence(3),
            'amount'         => fake()->numberBetween(5000, 500000),
            'expense_date'   => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'payment_method' => fake()->randomElement(array_keys(Expense::PAYMENT_METHODS)),
            'status'         => 'en_attente',
            'supplier_name'  => fake()->optional()->company(),
            'notes'          => fake()->optional()->sentence(),
        ];
    }

    public function validated(): static
    {
        return $this->state(fn () => ['status' => 'valide', 'approved_at' => now()]);
    }
}
