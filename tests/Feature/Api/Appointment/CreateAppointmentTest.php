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

/**
 * Eloquent formats dates as "Y-m-d H:i:s" without converting the timezone,
 * so times must be normalised to UTC before they are persisted. Offsets are
 * explicit (+07:00) so they line up with the requested start_at in the API calls.
 */
function utcTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time)->utc();
}

function bookingFixture(): array
{
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($customer)->create();
    $dealership = Dealership::factory()->create();
    $technician = Technician::factory()->for($dealership)->create(['active' => true]);
    $serviceBay = ServiceBay::factory()->for($dealership)->create(['active' => true]);
    $serviceType = ServiceType::factory()->create(['duration' => 60, 'active' => true]);

    Sanctum::actingAs($customer);

    return compact('customer', 'vehicle', 'dealership', 'technician', 'serviceBay', 'serviceType');
}

test('creates a confirmed appointment with server-derived end time and resources', function () {
    $fixture = bookingFixture();

    $response = $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.customer_id', $fixture['customer']->id)
        ->assertJsonPath('data.vehicle_id', $fixture['vehicle']->id)
        ->assertJsonPath('data.dealership_id', $fixture['dealership']->id)
        ->assertJsonPath('data.service_type_id', $fixture['serviceType']->id)
        ->assertJsonPath('data.technician_id', $fixture['technician']->id)
        ->assertJsonPath('data.service_bay_id', $fixture['serviceBay']->id)
        ->assertJsonPath('data.status', 'confirmed');

    $appointment = Appointment::query()->firstOrFail();
    expect($appointment->start_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 03:00:00')
        ->and($appointment->end_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 04:00:00');
});

test('rejects unauthenticated booking', function () {
    $this->postJson('/api/v1/appointments', [])->assertUnauthorized();
});

test('rejects another customers vehicle', function () {
    $fixture = bookingFixture();
    $otherCustomer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($otherCustomer)->create();

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $vehicle->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertUnprocessable();
});

test('rejects unavailable vehicle', function () {
    $fixture = bookingFixture();
    $fixture['vehicle']->update(['status' => 'unavailable']);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertUnprocessable();
});

test('rejects inactive dealership', function () {
    $fixture = bookingFixture();
    $fixture['dealership']->update(['active' => false]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertUnprocessable();
});

test('rejects inactive service type', function () {
    $fixture = bookingFixture();
    $fixture['serviceType']->update(['active' => false]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertUnprocessable();
});

test('rejects invalid service type duration', function () {
    $fixture = bookingFixture();
    $fixture['serviceType']->update(['duration' => 0]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertUnprocessable();
});

test('rejects missing or invalid start time', function () {
    $fixture = bookingFixture();

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
    ])->assertUnprocessable();

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01 10:00:00',
    ])->assertUnprocessable();
});

test('rejects client supplied server-owned booking fields', function () {
    $fixture = bookingFixture();

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
        'end_at' => '2026-11-01T11:00:00+07:00',
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'customer_id' => $fixture['customer']->id,
    ])->assertUnprocessable();
});

test('rejects when a technician overlaps', function () {
    $fixture = bookingFixture();
    Appointment::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => utcTime('2026-11-01T10:30:00+07:00'),
        'end_at' => utcTime('2026-11-01T11:30:00+07:00'),
        'status' => 'confirmed',
    ]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertStatus(409);
});

test('allows back to back appointment', function () {
    $fixture = bookingFixture();
    Appointment::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => utcTime('2026-11-01T10:00:00+07:00'),
        'end_at' => utcTime('2026-11-01T11:00:00+07:00'),
        'status' => 'confirmed',
    ]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T11:00:00+07:00',
    ])->assertCreated();
});

test('cancelled appointments do not block resources', function () {
    $fixture = bookingFixture();
    Appointment::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => utcTime('2026-11-01T10:00:00+07:00'),
        'end_at' => utcTime('2026-11-01T11:00:00+07:00'),
        'status' => 'cancelled',
    ]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertCreated();
});

test('rejects service bay overlap even when another technician is available', function () {
    $fixture = bookingFixture();
    $otherTechnician = Technician::factory()->for($fixture['dealership'])->create(['active' => true]);

    Appointment::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => utcTime('2026-11-01T10:30:00+07:00'),
        'end_at' => utcTime('2026-11-01T11:30:00+07:00'),
        'status' => 'confirmed',
    ]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertStatus(409);

    expect($otherTechnician->exists)->toBeTrue();
});

test('ignores soft deleted technicians and service bays when selecting resources', function () {
    $fixture = bookingFixture();
    $fixture['technician']->delete();
    $fixture['serviceBay']->delete();

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertStatus(409);
});

test('ignores cancelled overlapping appointments for both resources', function () {
    $fixture = bookingFixture();
    Appointment::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => utcTime('2026-11-01T09:30:00+07:00'),
        'end_at' => utcTime('2026-11-01T11:30:00+07:00'),
        'status' => 'cancelled',
    ]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertCreated();
});

it('applies the half-open overlap rule for the requested interval', function (string $existingStart, string $existingEnd, int $expectedStatus) {
    $fixture = bookingFixture();

    Appointment::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'technician_id' => $fixture['technician']->id,
        'service_bay_id' => $fixture['serviceBay']->id,
        'start_at' => utcTime($existingStart),
        'end_at' => utcTime($existingEnd),
        'status' => 'confirmed',
    ]);

    $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => '2026-11-01T10:00:00+07:00',
    ])->assertStatus($expectedStatus);
})->with([
    'exact overlap' => ['2026-11-01T10:00:00+07:00', '2026-11-01T11:00:00+07:00', 409],
    'requested start inside existing' => ['2026-11-01T09:30:00+07:00', '2026-11-01T10:30:00+07:00', 409],
    'requested end inside existing' => ['2026-11-01T10:30:00+07:00', '2026-11-01T11:30:00+07:00', 409],
    'existing inside requested' => ['2026-11-01T10:15:00+07:00', '2026-11-01T10:45:00+07:00', 409],
    'requested inside existing' => ['2026-11-01T09:30:00+07:00', '2026-11-01T11:30:00+07:00', 409],
    'back-to-back before existing' => ['2026-11-01T11:00:00+07:00', '2026-11-01T12:00:00+07:00', 201],
    'back-to-back after existing' => ['2026-11-01T09:00:00+07:00', '2026-11-01T10:00:00+07:00', 201],
]);
