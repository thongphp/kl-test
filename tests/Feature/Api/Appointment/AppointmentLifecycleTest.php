<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;

/**
 * An appointment that belongs to a different customer (and that customer's vehicle).
 */
function otherCustomersAppointment(object $booking): Appointment
{
    $otherCustomer = Customer::factory()->create();

    return existingAppointment($booking, [
        'customer_id' => $otherCustomer->id,
        'vehicle_id' => Vehicle::factory()->for($otherCustomer)->create()->id,
    ]);
}

/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

it('scopes the list to the authenticated customer', function (object $booking) {
    $owned = existingAppointment($booking);
    $other = otherCustomersAppointment($booking);

    $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $owned->id)
        ->assertJsonMissing(['id' => $other->id]);
})->with('booking');

it('returns the resource structure without internal fields', function (object $booking) {
    existingAppointment($booking);

    $response = $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => appointmentResourceStructure()],
            'links',
            'meta',
        ]);

    foreach (internalAppointmentFields() as $field) {
        $response->assertJsonMissingPath("data.0.{$field}");
    }
})->with('booking');

it('paginates with a default of 20 and a maximum of 100 per page', function (object $booking) {
    foreach (range(0, 20) as $day) {
        $start = CarbonImmutable::parse('2030-11-01T10:00:00+07:00')->addDays($day);

        existingAppointment($booking, [
            'start_at' => $start->utc(),
            'end_at' => $start->addHour()->utc(),
        ]);
    }

    $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 21);

    $this->getJson('/api/v1/appointments?page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2);

    $this->getJson('/api/v1/appointments?per_page=100')
        ->assertOk()
        ->assertJsonCount(21, 'data')
        ->assertJsonPath('meta.per_page', 100);

    $this->getJson('/api/v1/appointments?per_page=101')
        ->assertUnprocessable();
})->with('booking');

it('filters by status and vehicle_id', function (object $booking) {
    $secondVehicle = Vehicle::factory()->for($booking->customer)->create();

    $confirmed = existingAppointment($booking);
    $cancelled = existingAppointment($booking, ['status' => 'cancelled']);
    $secondVehicleAppointment = existingAppointment($booking, [
        'vehicle_id' => $secondVehicle->id,
        'start_at' => utcTime('2030-11-02T10:00:00+07:00'),
        'end_at' => utcTime('2030-11-02T11:00:00+07:00'),
    ]);

    $this->getJson('/api/v1/appointments?status=cancelled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cancelled->id);

    $this->getJson('/api/v1/appointments?vehicle_id='.$secondVehicle->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $secondVehicleAppointment->id)
        ->assertJsonMissing(['id' => $confirmed->id]);
})->with('booking');

it('orders by start_at descending and keeps cancelled appointments visible', function (object $booking) {
    $older = existingAppointment($booking);
    $newerCancelled = existingAppointment($booking, [
        'start_at' => utcTime('2030-11-03T10:00:00+07:00'),
        'end_at' => utcTime('2030-11-03T11:00:00+07:00'),
        'status' => 'cancelled',
    ]);

    $this->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newerCancelled->id)
        ->assertJsonPath('data.0.status', 'cancelled')
        ->assertJsonPath('data.1.id', $older->id);
})->with('booking');

/*
|--------------------------------------------------------------------------
| Detail and ownership
|--------------------------------------------------------------------------
*/

it('shows the customer their own appointment', function (object $booking) {
    $appointment = existingAppointment($booking);

    $response = $this->getJson('/api/v1/appointments/'.$appointment->id)
        ->assertOk()
        ->assertJsonStructure(['data' => appointmentResourceStructure()])
        ->assertJsonPath('data.id', $appointment->id)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.vehicle.id', $booking->vehicle->id)
        ->assertJsonPath('data.dealership.id', $booking->dealership->id)
        ->assertJsonPath('data.service_type.id', $booking->serviceType->id)
        ->assertJsonPath('data.technician.id', $booking->technician->id)
        ->assertJsonPath('data.service_bay.id', $booking->serviceBay->id);

    foreach (internalAppointmentFields() as $field) {
        $response->assertJsonMissingPath("data.{$field}");
    }
})->with('booking');

it("forbids viewing another customer's appointment", function (object $booking) {
    $appointment = otherCustomersAppointment($booking);

    $this->getJson('/api/v1/appointments/'.$appointment->id)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');
})->with('booking');

/*
|--------------------------------------------------------------------------
| Cancellation
|--------------------------------------------------------------------------
*/

it('cancels a future confirmed appointment and returns the resource', function (object $booking) {
    CarbonImmutable::setTestNow(utcTime('2030-10-01T10:00:00+07:00'));
    $appointment = existingAppointment($booking);

    $response = $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertOk()
        ->assertJsonStructure(['data' => appointmentResourceStructure()])
        ->assertJsonPath('data.id', $appointment->id)
        ->assertJsonPath('data.status', 'cancelled');

    foreach (internalAppointmentFields() as $field) {
        $response->assertJsonMissingPath("data.{$field}");
    }

    expect($appointment->fresh()->status)->toBe('cancelled');
})->with('booking');

it('has no minimum cancellation notice', function (object $booking) {
    CarbonImmutable::setTestNow(utcTime('2030-11-01T09:59:00+07:00'));
    $appointment = existingAppointment($booking);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
})->with('booking');

it('refuses to cancel started or already cancelled appointments', function (object $booking, string $now, string $status) {
    CarbonImmutable::setTestNow(utcTime($now));
    $appointment = existingAppointment($booking, ['status' => $status]);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('code', 'APPOINTMENT_CANNOT_BE_CANCELLED');

    expect($appointment->fresh()->status)->toBe($status);
})->with('booking')->with([
    'starting right now' => ['2030-11-01T10:00:00+07:00', 'confirmed'],
    'already in progress' => ['2030-11-01T10:30:00+07:00', 'confirmed'],
    'already cancelled' => ['2030-10-01T10:00:00+07:00', 'cancelled'],
]);

it("forbids cancelling another customer's appointment and leaves it untouched", function (object $booking) {
    CarbonImmutable::setTestNow(utcTime('2030-10-01T10:00:00+07:00'));
    $appointment = otherCustomersAppointment($booking);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($appointment->fresh()->status)->toBe('confirmed');
})->with('booking');

it('releases the resources of a cancelled appointment and rebooking creates a separate one', function (object $booking) {
    CarbonImmutable::setTestNow(utcTime('2030-10-01T10:00:00+07:00'));
    $original = existingAppointment($booking);

    $this->postJson('/api/v1/appointments/'.$original->id.'/cancel')->assertOk();

    $newId = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $booking->technician->id)
        ->assertJsonPath('data.service_bay.id', $booking->serviceBay->id)
        ->json('data.id');

    expect($newId)->not->toBe($original->id)
        ->and(Appointment::query()->count())->toBe(2)
        ->and($original->fresh()->status)->toBe('cancelled')
        ->and(Appointment::query()->findOrFail($newId)->status)->toBe('confirmed');
})->with('booking');
