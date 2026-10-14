<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->randomElement(['Model 3', 'Civic', 'Corolla', 'CX-5']),
            'brand' => fake()->randomElement(['Tesla', 'Honda', 'Toyota', 'Mazda']),
            'colors' => [fake()->safeColorName()],
            'pin' => strtoupper(fake()->unique()->bothify('???-#####')),
            'type' => 'car',
            'status' => 'available',
        ];
    }
}
