<?php

namespace Database\Factories;

use App\Models\Dealership;
use App\Models\ServiceBay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceBay> */
class ServiceBayFactory extends Factory
{
    protected $model = ServiceBay::class;

    public function definition(): array
    {
        return [
            'dealership_id' => Dealership::factory(),
            'name' => 'Bay '.fake()->unique()->numberBetween(1, 99),
            'location' => fake()->optional()->streetAddress(),
            'active' => true,
        ];
    }
}
