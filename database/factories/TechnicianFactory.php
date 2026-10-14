<?php

namespace Database\Factories;

use App\Models\Dealership;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Technician> */
class TechnicianFactory extends Factory
{
    protected $model = Technician::class;

    public function definition(): array
    {
        return [
            'dealership_id' => Dealership::factory(),
            'name' => fake()->name(),
            'active' => true,
        ];
    }
}
