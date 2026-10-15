<?php

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Eloquent formats dates as "Y-m-d H:i:s" without converting the timezone,
 * so times must be normalised to UTC before they are persisted. Offsets are
 * explicit (+07:00) so they line up with the requested start_at in the API calls.
 */
function utcTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time)->utc();
}

/**
 * Valid POST /api/v1/appointments payload for the "booking" dataset
 * (see tests/Datasets/Booking.php). Defaults to the 10:00 +07:00 slot.
 */
function bookingPayload(object $booking, string $startAt = '2030-11-01T10:00:00+07:00', array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $booking->vehicle->id,
        'dealership_id' => $booking->dealership->id,
        'service_type_id' => $booking->serviceType->id,
        'start_at' => $startAt,
    ], $overrides);
}

/**
 * An existing confirmed appointment that holds the booking's technician and
 * bay from 10:00 to 11:00 (+07:00), i.e. exactly the slot bookingPayload() requests.
 */
function existingAppointment(object $booking, array $overrides = []): Appointment
{
    return Appointment::factory()->create(array_merge([
        'customer_id' => $booking->customer->id,
        'vehicle_id' => $booking->vehicle->id,
        'dealership_id' => $booking->dealership->id,
        'service_type_id' => $booking->serviceType->id,
        'technician_id' => $booking->technician->id,
        'service_bay_id' => $booking->serviceBay->id,
        'start_at' => utcTime('2030-11-01T10:00:00+07:00'),
        'end_at' => utcTime('2030-11-01T11:00:00+07:00'),
        'status' => 'confirmed',
    ], $overrides));
}

/**
 * JSON structure of a single AppointmentResource.
 */
function appointmentResourceStructure(): array
{
    return [
        'id',
        'status',
        'start_at',
        'end_at',
        'vehicle' => ['id', 'name'],
        'dealership' => ['id', 'name'],
        'service_type' => ['id', 'name'],
        'technician' => ['id', 'name'],
        'service_bay' => ['id', 'name'],
    ];
}

/**
 * Fields that must never be exposed by AppointmentResource.
 */
function internalAppointmentFields(): array
{
    return [
        'customer_id',
        'vehicle_id',
        'dealership_id',
        'service_type_id',
        'technician_id',
        'service_bay_id',
        'deleted_at',
    ];
}

pest()
    ->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->afterEach(fn () => CarbonImmutable::setTestNow())
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Concurrency suite (Phase 9)
|--------------------------------------------------------------------------
|
| Real parallel requests run in separate PHP processes, so data must be
| COMMITTED. RefreshDatabase wraps each test in a transaction that child
| processes cannot see, so this folder uses DatabaseTruncation instead.
| Needs PostgreSQL/MySQL: SQLite ignores lockForUpdate().
|
| Run with: php artisan test tests/Concurrency
|
*/
pest()
    ->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\DatabaseTruncation::class)
    ->beforeEach(function () {
        if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs PostgreSQL (set DB_CONNECTION=pgsql). See Phase 9.');
        }
    })
    ->afterEach(fn () => CarbonImmutable::setTestNow())
    ->in('Concurrency');
