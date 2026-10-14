<?php

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\Technician;

/*
|--------------------------------------------------------------------------
| Resource selection
|--------------------------------------------------------------------------
*/

it('ignores inactive technicians and service bays', function (object $booking, string $resource) {
    $booking->{$resource}->update(['active' => false]);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect(Appointment::query()->count())->toBe(0);
})->with('booking')->with(['technician', 'serviceBay']);

it('ignores soft deleted resources and resources belonging to another dealership', function (object $booking) {
    $otherDealership = Dealership::factory()->create();
    Technician::factory()->for($otherDealership)->create(['active' => true]);
    ServiceBay::factory()->for($otherDealership)->create(['active' => true]);

    $booking->technician->delete();
    $booking->serviceBay->delete();

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect(Appointment::query()->count())->toBe(0);
})->with('booking');

it("never falls back to another dealership's free resources", function (object $booking) {
    existingAppointment($booking);

    $otherDealership = Dealership::factory()->create();
    $otherTechnician = Technician::factory()->for($otherDealership)->create(['active' => true]);
    $otherBay = ServiceBay::factory()->for($otherDealership)->create(['active' => true]);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect(Appointment::query()->where('technician_id', $otherTechnician->id)->exists())->toBeFalse()
        ->and(Appointment::query()->where('service_bay_id', $otherBay->id)->exists())->toBeFalse();
})->with('booking');

it('selects technician and bay deterministically by lowest id', function (object $booking) {
    $secondTechnician = Technician::factory()->for($booking->dealership)->create(['active' => true]);
    $secondBay = ServiceBay::factory()->for($booking->dealership)->create(['active' => true]);

    $expectedTechnician = collect([$booking->technician, $secondTechnician])->sortBy('id')->first();
    $expectedBay = collect([$booking->serviceBay, $secondBay])->sortBy('id')->first();

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $expectedTechnician->id)
        ->assertJsonPath('data.service_bay.id', $expectedBay->id);
})->with('booking');

it('skips busy resources and selects the next free one', function (object $booking) {
    $secondTechnician = Technician::factory()->for($booking->dealership)->create(['active' => true]);
    $secondBay = ServiceBay::factory()->for($booking->dealership)->create(['active' => true]);

    // Make the lowest-id technician and bay the busy ones, so "lowest id" alone would pick them.
    [$busyTechnician, $freeTechnician] = collect([$booking->technician, $secondTechnician])->sortBy('id')->values()->all();
    [$busyBay, $freeBay] = collect([$booking->serviceBay, $secondBay])->sortBy('id')->values()->all();

    existingAppointment($booking, [
        'technician_id' => $busyTechnician->id,
        'service_bay_id' => $busyBay->id,
    ]);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $freeTechnician->id)
        ->assertJsonPath('data.service_bay.id', $freeBay->id);
})->with('booking');

/*
|--------------------------------------------------------------------------
| Overlap rules, checked per resource type
|--------------------------------------------------------------------------
|
| The existing appointment holds the original technician AND bay. Adding one
| free resource of the other kind means only one resource is contended, so
| every interval proves that resource's own overlap check in isolation.
|
*/

it('applies the half-open overlap rule to each resource type', function (
    object $booking,
    string $existingStart,
    string $existingEnd,
    int $expectedStatus,
    string $freeResource,
) {
    existingAppointment($booking, [
        'start_at' => utcTime($existingStart),
        'end_at' => utcTime($existingEnd),
    ]);

    $freeResource::factory()->for($booking->dealership)->create(['active' => true]);

    // Requested slot is always 10:00 - 11:00 (+07:00).
    $response = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus($expectedStatus);

    if ($expectedStatus === 409) {
        $response->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');
        expect(Appointment::query()->count())->toBe(1);
    } else {
        expect(Appointment::query()->count())->toBe(2);
    }
})->with('booking')->with([
    'exact overlap' => ['2030-11-01T10:00:00+07:00', '2030-11-01T11:00:00+07:00', 409],
    'requested start inside existing' => ['2030-11-01T09:30:00+07:00', '2030-11-01T10:30:00+07:00', 409],
    'requested end inside existing' => ['2030-11-01T10:30:00+07:00', '2030-11-01T11:30:00+07:00', 409],
    'existing inside requested' => ['2030-11-01T10:15:00+07:00', '2030-11-01T10:45:00+07:00', 409],
    'requested inside existing' => ['2030-11-01T09:30:00+07:00', '2030-11-01T11:30:00+07:00', 409],
    'back-to-back before existing' => ['2030-11-01T11:00:00+07:00', '2030-11-01T12:00:00+07:00', 201],
    'back-to-back after existing' => ['2030-11-01T09:00:00+07:00', '2030-11-01T10:00:00+07:00', 201],
])->with([
    'technician busy, bay free' => [ServiceBay::class],
    'bay busy, technician free' => [Technician::class],
]);

it('does not let cancelled appointments block resources', function (object $booking, string $existingStart, string $existingEnd) {
    existingAppointment($booking, [
        'start_at' => utcTime($existingStart),
        'end_at' => utcTime($existingEnd),
        'status' => 'cancelled',
    ]);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $booking->technician->id)
        ->assertJsonPath('data.service_bay.id', $booking->serviceBay->id);
})->with('booking')->with([
    'same slot' => ['2030-11-01T10:00:00+07:00', '2030-11-01T11:00:00+07:00'],
    'wider overlapping slot' => ['2030-11-01T09:30:00+07:00', '2030-11-01T11:30:00+07:00'],
]);
