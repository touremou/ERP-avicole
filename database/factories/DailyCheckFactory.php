<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\DailyCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DailyCheckFactory extends Factory
{
    protected $model = DailyCheck::class;

    public function definition(): array
    {
        return [
            'uuid'               => (string) Str::uuid(),
            'batch_id'           => Batch::factory(),
            'check_date'         => fake()->dateTimeBetween('-30 days', 'now'),
            'mortality'          => fake()->numberBetween(0, 5),
            'avg_weight'         => fake()->randomFloat(3, 0.05, 3.5),
            'feed_consumed'      => fake()->randomFloat(1, 10, 200),
            'feed_type'          => fake()->randomElement(['Chair Démarrage', 'Chair Croissance', 'Chair Finition']),
            'water_consumed'     => fake()->randomFloat(1, 20, 500),
            'qty_quarantine_in'  => 0,
            'qty_quarantine_out' => 0,
            'qty_sorted_out'     => 0,
            'is_synced'          => true,
        ];
    }
}
