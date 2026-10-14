<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

function phase6Fixture(): array
{
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($customer)->create();
    $secondVehicle = Vehicle::factory()->for($customer)->create();
    $dealership = Dealership::factory()->create();
    $technician = Technician::factory()->for($dealership)->create(['active' => true]);
    $serviceBay = ServiceBay::factory()->for($dealership)->create(['active' => true]);
    $serviceType = ServiceType::factory()->create(['duration' => 60, 'active' => true]);

    Sanctum::actingAs($customer);

    return compact('customer', 'vehicle', 'secondVehicle', 'dealership', 'technician', 'serviceBay', 'serviceType');
}

function phase6AppointmentPayload(array $fixture, string $startAt = '2030-11-01T10:00:00+07:00'): array
{
    return [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => $startAt,
    ];
}

function phase6CreateAppointment(array $fixture, array $overrides = []): Appointment
{
    return Appointment::factory()->create(array_merge([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => CarbonImmutable::parse('2030-11-01T10:00:00+07:00')->utc(),
        'end_at' => CarbonImmutable::parse('2030-11-01T11:00:00+07:00')->utc(),
        'status' => 'confirmed',
    ], $overrides));
}

test('idempotent retry returns the same AppointmentResource representation', function () {
    $fixture = phase6Fixture();
    $payload = phase6AppointmentPayload($fixture);

    $first = $this->withHeader('Idempotency-Key', 'phase6-resource-replay')
        ->postJson('/api/v1/appointments', $payload)
        ->assertCreated();

    $second = $this->withHeader('Idempotency-Key', 'phase6-resource-replay')
        ->postJson('/api/v1/appointments', $payload)
        ->assertOk();

    $second->assertExactJson($first->json())
        ->assertJsonMissingPath('data.customer_id')
        ->assertJsonMissingPath('data.vehicle_id')
        ->assertJsonPath('data.vehicle.id', $fixture['vehicle']->id)
        ->assertJsonPath('data.dealership.id', $fixture['dealership']->id)
        ->assertJsonPath('data.service_type.id', $fixture['serviceType']->id)
        ->assertJsonPath('data.technician.id', $fixture['technician']->id)
        ->assertJsonPath('data.service_bay.id', $fixture['serviceBay']->id);
});

test('appointment list is scoped to the authenticated customer', function () {
    $fixture = phase6Fixture();
    $owned = phase6CreateAppointment($fixture);
    $otherCustomer = Customer::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCustomer)->create();
    $other = phase6CreateAppointment($fixture, [
        'customer_id' => $otherCustomer->id,
        'vehicle_id' => $otherVehicle->id,
    ]);

    $response = $this->getJson('/api/v1/appointments')->assertOk();

    $response->assertJsonPath('data.0.id', $owned->id)
        ->assertJsonMissing(['id' => $other->id]);
});

test('appointment list defaults to 20 items and rejects more than 100 per page', function () {
    $fixture = phase6Fixture();

    for ($i = 0; $i < 21; $i++) {
        phase6CreateAppointment($fixture, [
            'start_at' => CarbonImmutable::parse("2030-11-01 10:{$i}:00", 'UTC'),
            'end_at' => CarbonImmutable::parse("2030-11-01 11:{$i}:00", 'UTC'),
        ]);
    }

    $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20);

    $this->getJson('/api/v1/appointments?page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2);

    $this->getJson('/api/v1/appointments?per_page=101')
        ->assertUnprocessable();

    $this->getJson('/api/v1/appointments?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('appointment list supports status and vehicle filters', function () {
    $fixture = phase6Fixture();
    $confirmed = phase6CreateAppointment($fixture, [
        'vehicle_id' => $fixture['vehicle']->id,
        'status' => 'confirmed',
    ]);
    $cancelled = phase6CreateAppointment($fixture, [
        'vehicle_id' => $fixture['vehicle']->id,
        'status' => 'cancelled',
    ]);
    $secondVehicleAppointment = phase6CreateAppointment($fixture, [
        'vehicle_id' => $fixture['secondVehicle']->id,
        'start_at' => CarbonImmutable::parse('2030-11-02T10:00:00+00:00'),
        'end_at' => CarbonImmutable::parse('2030-11-02T11:00:00+00:00'),
    ]);

    $this->getJson('/api/v1/appointments?status=cancelled')
        ->assertOk()
        ->assertJsonPath('data.0.id', $cancelled->id)
        ->assertJsonMissing(['id' => $confirmed->id]);

    $this->getJson('/api/v1/appointments?vehicle_id='.$fixture['secondVehicle']->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $secondVehicleAppointment->id)
        ->assertJsonMissing(['id' => $confirmed->id]);
});

test('appointment list orders by start_at descending and keeps cancelled appointments visible', function () {
    $fixture = phase6Fixture();
    $older = phase6CreateAppointment($fixture, [
        'start_at' => CarbonImmutable::parse('2030-11-01T10:00:00+00:00'),
        'end_at' => CarbonImmutable::parse('2030-11-01T11:00:00+00:00'),
    ]);
    $newerCancelled = phase6CreateAppointment($fixture, [
        'start_at' => CarbonImmutable::parse('2030-11-03T10:00:00+00:00'),
        'end_at' => CarbonImmutable::parse('2030-11-03T11:00:00+00:00'),
        'status' => 'cancelled',
    ]);

    $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newerCancelled->id)
        ->assertJsonPath('data.0.status', 'cancelled')
        ->assertJsonPath('data.1.id', $older->id);
});

test('customer can view their own appointment through AppointmentPolicy and resource', function () {
    $fixture = phase6Fixture();
    $appointment = phase6CreateAppointment($fixture);

    $this->getJson('/api/v1/appointments/'.$appointment->id)
        ->assertOk()
        ->assertJsonPath('data.id', $appointment->id)
        ->assertJsonPath('data.vehicle.id', $fixture['vehicle']->id)
        ->assertJsonPath('data.dealership.id', $fixture['dealership']->id)
        ->assertJsonPath('data.service_type.id', $fixture['serviceType']->id)
        ->assertJsonPath('data.technician.id', $fixture['technician']->id)
        ->assertJsonPath('data.service_bay.id', $fixture['serviceBay']->id)
        ->assertJsonMissingPath('data.customer_id');
});

test('customer cannot view another customers appointment', function () {
    $fixture = phase6Fixture();
    $otherCustomer = Customer::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCustomer)->create();
    $appointment = phase6CreateAppointment($fixture, [
        'customer_id' => $otherCustomer->id,
        'vehicle_id' => $otherVehicle->id,
    ]);

    $this->getJson('/api/v1/appointments/'.$appointment->id)
        ->assertForbidden();
});

test('customer can cancel a future confirmed appointment and receives the resource', function () {
    $fixture = phase6Fixture();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-10-01T10:00:00+00:00'));
    $appointment = phase6CreateAppointment($fixture);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.id', $appointment->id)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonMissingPath('data.customer_id');

    expect($appointment->fresh()->status)->toBe('cancelled');

    CarbonImmutable::setTestNow();
});

test('started appointment cannot be cancelled', function () {
    $fixture = phase6Fixture();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-11-01T10:00:00+00:00'));
    $appointment = phase6CreateAppointment($fixture);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('code', 'APPOINTMENT_CANNOT_BE_CANCELLED');

    expect($appointment->fresh()->status)->toBe('confirmed');

    CarbonImmutable::setTestNow();
});

test('already cancelled appointment cannot be cancelled again', function () {
    $fixture = phase6Fixture();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-10-01T10:00:00+00:00'));
    $appointment = phase6CreateAppointment($fixture, ['status' => 'cancelled']);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('code', 'APPOINTMENT_CANNOT_BE_CANCELLED');

    CarbonImmutable::setTestNow();
});

test('customer cannot cancel another customers appointment', function () {
    $fixture = phase6Fixture();
    $otherCustomer = Customer::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCustomer)->create();
    $appointment = phase6CreateAppointment($fixture, [
        'customer_id' => $otherCustomer->id,
        'vehicle_id' => $otherVehicle->id,
    ]);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertForbidden();
});

test('cancelled appointment no longer blocks its technician and service bay', function () {
    $fixture = phase6Fixture();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-10-01T10:00:00+00:00'));
    $appointment = phase6CreateAppointment($fixture);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertOk();

    $this->postJson('/api/v1/appointments', phase6AppointmentPayload($fixture))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $fixture['technician']->id)
        ->assertJsonPath('data.service_bay.id', $fixture['serviceBay']->id);

    CarbonImmutable::setTestNow();
});
