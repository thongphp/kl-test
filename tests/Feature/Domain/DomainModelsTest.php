<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\IdempotencyKey;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;

it('creates the phase 1 domain model graph', function () {
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->create(['customer_id' => $customer->id]);
    $dealership = Dealership::factory()->create();
    $technician = Technician::factory()->create(['dealership_id' => $dealership->id]);
    $serviceBay = ServiceBay::factory()->create(['dealership_id' => $dealership->id]);
    $serviceType = ServiceType::factory()->create();

    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'dealership_id' => $dealership->id,
        'service_type_id' => $serviceType->id,
        'technician_id' => $technician->id,
        'service_bay_id' => $serviceBay->id,
    ]);

    expect($customer->vehicles->modelKeys())->toContain($vehicle->id)
        ->and($customer->appointments->modelKeys())->toContain($appointment->id)
        ->and($vehicle->appointments->modelKeys())->toContain($appointment->id)
        ->and($dealership->technicians->modelKeys())->toContain($technician->id)
        ->and($dealership->serviceBays->modelKeys())->toContain($serviceBay->id)
        ->and($dealership->appointments->modelKeys())->toContain($appointment->id)
        ->and($serviceType->appointments->modelKeys())->toContain($appointment->id)
        ->and($technician->appointments->modelKeys())->toContain($appointment->id)
        ->and($serviceBay->appointments->modelKeys())->toContain($appointment->id);
});

it('uses uuid primary keys for phase 1 models', function () {
    $models = [
        Customer::factory()->create(),
        Vehicle::factory()->create(),
        Dealership::factory()->create(),
        Technician::factory()->create(),
        ServiceBay::factory()->create(),
        ServiceType::factory()->create(),
        Appointment::factory()->create(),
        IdempotencyKey::factory()->create(),
    ];

    foreach ($models as $model) {
        expect($model->getKey())->toBeString()
            ->and($model->getKeyName())->toBe('id')
            ->and($model->getKeyType())->toBe('string')
            ->and($model->getIncrementing())->toBeFalse();
    }
});

it('excludes soft deleted resources from normal queries', function () {
    $dealership = Dealership::factory()->create();

    $dealership->delete();

    expect(Dealership::query()->find($dealership->id))->toBeNull()
        ->and(Dealership::withTrashed()->find($dealership->id))->not->toBeNull();
});
