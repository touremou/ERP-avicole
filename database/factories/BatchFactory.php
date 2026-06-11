<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        $qty = fake()->numberBetween(500, 5000);

        return [
            'uuid'                   => (string) Str::uuid(),
            'code'                   => 'LOT-' . fake()->unique()->numerify('####'),
            'type'                   => fake()->randomElement(['chair', 'ponte', 'reproducteur']),
            'model_name'             => fake()->randomElement(['Cobb500', 'Ross308', 'ISA Brown', 'Lohmann']),
            'building_id'            => Building::factory(),
            'employee_id'            => Employee::factory(),
            'provider_id'            => Provider::factory(),
            'initial_quantity'       => $qty,
            'current_quantity'       => $qty,
            'qty_alive'              => $qty,
            'qty_dead'               => 0,
            'qty_males'              => 0,
            'qty_females'            => 0,
            'mating_ratio'           => 0,
            'status'                 => 'Actif',
            'arrival_date'           => fake()->dateTimeBetween('-60 days', 'now'),
            'expected_end_date'      => fake()->dateTimeBetween('+30 days', '+180 days'),
            'buy_price_per_unit'     => fake()->numberBetween(2000, 5000),
            'total_acquisition_cost' => $qty * fake()->numberBetween(2000, 5000),
            'is_synced'              => true,
            'production_phase'       => 'demarrage',
            'arrival_mortality_rate' => 0,
            'chick_state'            => 'Normal',
            'behavior'               => 'Normal',
            'avg_weight_start'       => fake()->randomFloat(3, 0.03, 0.08),
            'age_at_arrival'         => fake()->numberBetween(1, 7),
            'vaccination_received'   => false,
            'planned_density'        => fake()->randomFloat(1, 10, 15),
        ];
    }
}