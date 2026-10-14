<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+30 days');

        return [
            'customer_id' => Customer::factory(),
            'vehicle_id' => Vehicle::factory(),
            'dealership_id' => Dealership::factory(),
            'service_type_id' => ServiceType::factory(),
            'technician_id' => Technician::factory(),
            'service_bay_id' => ServiceBay::factory(),
            'start_at' => $start,
            'end_at' => (clone $start)->modify('+60 minutes'),
            'status' => 'confirmed',
        ];
    }
}
