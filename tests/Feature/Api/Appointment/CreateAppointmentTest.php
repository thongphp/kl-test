<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| POST /api/v1/appointments: success
|--------------------------------------------------------------------------
*/

it('creates a confirmed appointment with server-derived end time and resources', function (object $booking) {
    $response = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->assertJsonStructure(['data' => appointmentResourceStructure()])
        ->assertJsonPath('data.id', Appointment::query()->firstOrFail()->id)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.vehicle.id', $booking->vehicle->id)
        ->assertJsonPath('data.vehicle.name', $booking->vehicle->name)
        ->assertJsonPath('data.dealership.id', $booking->dealership->id)
        ->assertJsonPath('data.dealership.name', $booking->dealership->name)
        ->assertJsonPath('data.service_type.id', $booking->serviceType->id)
        ->assertJsonPath('data.service_type.name', $booking->serviceType->name)
        ->assertJsonPath('data.technician.id', $booking->technician->id)
        ->assertJsonPath('data.technician.name', $booking->technician->name)
        ->assertJsonPath('data.service_bay.id', $booking->serviceBay->id)
        ->assertJsonPath('data.service_bay.name', $booking->serviceBay->name);

    foreach (internalAppointmentFields() as $field) {
        $response->assertJsonMissingPath("data.{$field}");
    }

    $appointment = Appointment::query()->firstOrFail();

    expect($appointment->customer_id)->toBe($booking->customer->id)
        ->and($appointment->start_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-01 03:00:00')
        ->and($appointment->end_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-01 04:00:00');
})->with('booking');

it('derives end_at from the service type duration', function (object $booking, int $minutes) {
    $booking->serviceType->update(['duration' => $minutes]);

    $id = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->json('data.id');

    $appointment = Appointment::query()->findOrFail($id);

    expect($appointment->end_at->utc()->equalTo($appointment->start_at->copy()->utc()->addMinutes($minutes)))->toBeTrue();
})->with('booking')->with([
    '30 minutes' => 30,
    '90 minutes' => 90,
    '180 minutes' => 180,
]);

it('normalises any requested UTC offset to UTC storage', function (object $booking, string $startAt) {
    $this->postJson('/api/v1/appointments', bookingPayload($booking, $startAt))->assertCreated();

    expect(Appointment::query()->firstOrFail()->start_at->utc()->format('Y-m-d H:i:s'))
        ->toBe('2030-11-01 03:00:00');
})->with('booking')->with([
    '+07:00' => '2030-11-01T10:00:00+07:00',
    'UTC' => '2030-11-01T03:00:00+00:00',
    'negative offset' => '2030-10-31T22:00:00-05:00',
]);

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('reports every required field when the payload is empty', function () {
    Sanctum::actingAs(Customer::factory()->create());

    $this->postJson('/api/v1/appointments', [])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors(['vehicle_id', 'dealership_id', 'service_type_id', 'start_at']);
});

it('rejects a missing or malformed start time', function (object $booking, ?string $startAt) {
    $payload = Arr::except(bookingPayload($booking), 'start_at');

    if ($startAt !== null) {
        $payload['start_at'] = $startAt;
    }

    $this->postJson('/api/v1/appointments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_at']);
})->with('booking')->with([
    'missing' => [null],
    'without timezone offset' => ['2030-11-01 10:00:00'],
]);

it('rejects client supplied server-owned booking fields', function (object $booking, string $field) {
    $values = [
        'end_at' => '2030-11-01T18:00:00+07:00',
        'technician_id' => $booking->technician->id,
        'service_bay_id' => $booking->serviceBay->id,
        'customer_id' => $booking->customer->id,
    ];

    $this->postJson('/api/v1/appointments', bookingPayload($booking, overrides: [$field => $values[$field]]))
        ->assertUnprocessable();

    expect(Appointment::query()->count())->toBe(0);
})->with('booking')->with(['end_at', 'technician_id', 'service_bay_id', 'customer_id']);

it('rejects unknown related records', function (object $booking, string $field) {
    $this->postJson('/api/v1/appointments', bookingPayload($booking, overrides: [$field => (string) Str::uuid()]))
        ->assertUnprocessable();
})->with('booking')->with(['vehicle_id', 'dealership_id', 'service_type_id']);

/*
|--------------------------------------------------------------------------
| Ownership and business rules
|--------------------------------------------------------------------------
*/

it("rejects another customer's vehicle with a 422 validation error", function (object $booking) {
    $otherVehicle = Vehicle::factory()->for(Customer::factory())->create();

    $this->postJson('/api/v1/appointments', bookingPayload($booking, overrides: [
        'vehicle_id' => $otherVehicle->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors']);

    expect(Appointment::query()->count())->toBe(0);
})->with('booking');

it('rejects an unavailable vehicle', function (object $booking) {
    $booking->vehicle->update(['status' => 'unavailable']);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertUnprocessable();
})->with('booking');

it('rejects an inactive dealership', function (object $booking) {
    $booking->dealership->update(['active' => false]);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DEALERSHIP_INACTIVE');
})->with('booking');

it('rejects an unusable service type', function (object $booking, array $state, string $code) {
    $booking->serviceType->update($state);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertUnprocessable()
        ->assertJsonPath('code', $code);
})->with('booking')->with([
    'inactive' => [['active' => false], 'SERVICE_TYPE_INACTIVE'],
    'invalid duration' => [['duration' => 0], 'SERVICE_TYPE_INVALID_DURATION'],
    'negative duration' => [['duration' => -30], 'SERVICE_TYPE_INVALID_DURATION'],
]);
