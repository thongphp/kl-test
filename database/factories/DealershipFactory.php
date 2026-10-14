<?php

namespace Database\Factories;

use App\Models\Dealership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dealership> */
class DealershipFactory extends Factory
{
    protected $model = Dealership::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Motors',
            'timezone' => 'UTC',
            'active' => true,
        ];
    }
}
