<?php

namespace Database\Factories;

use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceType> */
class ServiceTypeFactory extends Factory
{
    protected $model = ServiceType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Oil Change', 'Brake Inspection', 'Tire Rotation', 'General Service']),
            'duration' => fake()->randomElement([30, 45, 60, 90]),
            'active' => true,
        ];
    }
}
