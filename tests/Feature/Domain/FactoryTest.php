<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\IdempotencyKey;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;

it('creates valid factories for the phase 1 domain', function () {
    expect(Customer::factory()->create())->toBeInstanceOf(Customer::class)
        ->and(Vehicle::factory()->create())->toBeInstanceOf(Vehicle::class)
        ->and(Dealership::factory()->create())->toBeInstanceOf(Dealership::class)
        ->and(Technician::factory()->create())->toBeInstanceOf(Technician::class)
        ->and(ServiceBay::factory()->create())->toBeInstanceOf(ServiceBay::class)
        ->and(ServiceType::factory()->create())->toBeInstanceOf(ServiceType::class)
        ->and(Appointment::factory()->create())->toBeInstanceOf(Appointment::class)
        ->and(IdempotencyKey::factory()->create())->toBeInstanceOf(IdempotencyKey::class);
});
