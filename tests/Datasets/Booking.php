<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| booking
|--------------------------------------------------------------------------
|
| The single shared fixture for the whole appointment suite (it replaces the
| four copies that used to live in each test file). Pest auto-loads everything
| in tests/Datasets, so a test only needs ->with('booking') and receives the
| object below as an argument.
|
| The closure is resolved lazily, after the app is booted and RefreshDatabase
| has run, so using factories here is safe. The customer is authenticated via
| Sanctum as part of the fixture.
|
| IMPORTANT: when combining this dataset with another one, type-hint the
| parameter (`object $booking`). Pest skips resolving lazy closures for
| parameters that are untyped (or typed `mixed`) and you would receive the
| raw Closure instead.
|
*/
dataset('booking', [
    'customer with vehicle, dealership, technician, bay and 60 min service' => [
        function (): object {
            $customer = Customer::factory()->create();
            $vehicle = Vehicle::factory()->for($customer)->create();
            $dealership = Dealership::factory()->create();
            $technician = Technician::factory()->for($dealership)->create(['active' => true]);
            $serviceBay = ServiceBay::factory()->for($dealership)->create(['active' => true]);
            $serviceType = ServiceType::factory()->create(['duration' => 60, 'active' => true]);

            Sanctum::actingAs($customer);

            return (object) compact(
                'customer',
                'vehicle',
                'dealership',
                'technician',
                'serviceBay',
                'serviceType',
            );
        },
    ],
]);

/*
|--------------------------------------------------------------------------
| core models
|--------------------------------------------------------------------------
|
| One persisted instance of every soft-deletable core model.
|
*/
dataset('core models', [
    'customer' => [fn () => Customer::factory()->create()],
    'vehicle' => [fn () => Vehicle::factory()->for(Customer::factory())->create()],
    'dealership' => [fn () => Dealership::factory()->create()],
    'technician' => [fn () => Technician::factory()->for(Dealership::factory())->create()],
    'service bay' => [fn () => ServiceBay::factory()->for(Dealership::factory())->create()],
    'service type' => [fn () => ServiceType::factory()->create()],
    'appointment' => [fn () => Appointment::factory()->create()],
]);
