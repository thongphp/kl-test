<?php

use App\Models\Appointment;
use Illuminate\Support\Arr;

/*
|--------------------------------------------------------------------------
| start_at must be in the future
| (needs 'after:now' in CreateAppointmentRequest rules for start_at)
|--------------------------------------------------------------------------
*/

it('rejects a start time in the past', function (object $booking, string $startAt) {
    $this->postJson('/api/v1/appointments', bookingPayload($booking, $startAt))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_at']);

    expect(Appointment::query()->count())->toBe(0);
})->with('booking')->with([
    'long ago' => ['2020-01-01T10:00:00+07:00'],
    'one minute ago' => [fn () => now()->subMinute()->toIso8601String()],
]);

it('accepts a start time in the near future', function (object $booking) {
    $this->postJson('/api/v1/appointments', bookingPayload($booking, now()->addHour()->toIso8601String()))
        ->assertCreated();
})->with('booking');

/*
|--------------------------------------------------------------------------
| Dealership timezone
|--------------------------------------------------------------------------
*/

it('stores the dealership timezone', function (object $booking) {
    expect($booking->dealership->fresh()->timezone)->toBe('UTC');

    $booking->dealership->forceFill(['timezone' => 'Asia/Bangkok'])->save();

    expect($booking->dealership->fresh()->timezone)->toBe('Asia/Bangkok');
})->with('booking');

it('keeps the service duration exact across a DST change', function (object $booking) {
    $booking->dealership->forceFill(['timezone' => 'America/New_York'])->save();

    // 01:30 EDT on 2030-11-03, the clock falls back an hour at 02:00 EDT.
    $id = $this->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-03T01:30:00-04:00'))
        ->assertCreated()
        ->json('data.id');

    $appointment = Appointment::query()->findOrFail($id);

    expect($appointment->start_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-03 05:30:00')
        ->and($appointment->end_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-03 06:30:00');
})->with('booking');

/*
| Display in dealership timezone
| (needs the AppointmentResource patch: start_at / end_at via setTimezone(dealership timezone))
*/

it('displays appointment times in the dealership timezone', function (object $booking, string $timezone, string $startUtc, string $expectedStart, string $expectedEnd) {
    $booking->dealership->forceFill(['timezone' => $timezone])->save();

    $id = $this->postJson('/api/v1/appointments', bookingPayload($booking, $startUtc))
        ->assertCreated()
        ->assertJsonPath('data.start_at', $expectedStart)
        ->assertJsonPath('data.end_at', $expectedEnd)
        ->json('data.id');

    $this->getJson('/api/v1/appointments/'.$id)
        ->assertOk()
        ->assertJsonPath('data.start_at', $expectedStart)
        ->assertJsonPath('data.end_at', $expectedEnd);
})->with('booking')->with([
    'UTC' => ['UTC', '2030-11-01T03:00:00+00:00', '2030-11-01T03:00:00+00:00', '2030-11-01T04:00:00+00:00'],
    'Bangkok' => ['Asia/Bangkok', '2030-11-01T03:00:00+00:00', '2030-11-01T10:00:00+07:00', '2030-11-01T11:00:00+07:00'],
    'New York winter' => ['America/New_York', '2030-12-01T15:00:00+00:00', '2030-12-01T10:00:00-05:00', '2030-12-01T11:00:00-05:00'],
    'New York summer' => ['America/New_York', '2030-07-01T14:00:00+00:00', '2030-07-01T10:00:00-04:00', '2030-07-01T11:00:00-04:00'],
]);
