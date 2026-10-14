<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IdempotencyKey> */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'key' => fake()->unique()->uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'appointment_id' => null,
            'response_status' => null,
            'expires_at' => now()->addHours(24),
        ];
    }
}
