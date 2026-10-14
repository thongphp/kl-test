<?php

use App\Exceptions\BookingInProgressException;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\Technician;
use App\Models\Vehicle;
use App\Services\RedisAvailabilityService;
use App\Services\RedisBookingLockService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Transactions
|--------------------------------------------------------------------------
*/

it('rolls back the appointment and never updates Redis when persistence fails after the insert', function (object $booking) {
    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')->with($booking->dealership->id)->andReturn(null);

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->andReturn(null);
    $availabilityService->shouldReceive('candidateServiceBayIds')->andReturn(null);
    $availabilityService->shouldNotReceive('indexAppointment');

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    // Throws AFTER the row is inserted, so this proves a real rollback.
    Appointment::created(fn () => throw new RuntimeException('simulated failure after insert'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/v1/appointments', bookingPayload($booking)))
        ->toThrow(RuntimeException::class);

    expect(Appointment::query()->count())->toBe(0);
})->with('booking');

/*
|--------------------------------------------------------------------------
| Redis is advisory: lock, availability index and DB fallback
|--------------------------------------------------------------------------
*/

it('maps Redis dealership lock contention to a booking in progress conflict', function (object $booking) {
    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')
        ->once()
        ->with($booking->dealership->id)
        ->andThrow(new BookingInProgressException());

    $this->app->instance(RedisBookingLockService::class, $lockService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'BOOKING_IN_PROGRESS');

    expect(Appointment::query()->count())->toBe(0);
})->with('booking');

it('can book again once the dealership lock is free', function (object $booking) {
    $attempts = 0;

    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')
        ->twice()
        ->with($booking->dealership->id)
        ->andReturnUsing(function () use (&$attempts) {
            if ($attempts++ === 0) {
                throw new BookingInProgressException();
            }

            return null;
        });

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->andReturn(null);
    $availabilityService->shouldReceive('candidateServiceBayIds')->andReturn(null);
    $availabilityService->shouldReceive('indexAppointment');

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'BOOKING_IN_PROGRESS');

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertCreated();

    expect(Appointment::query()->count())->toBe(1);
})->with('booking');

it('falls back to the database when Redis availability is unavailable', function (object $booking) {
    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')
        ->once()
        ->with($booking->dealership->id)
        ->andReturn(null);

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->once()->andReturn(null);
    $availabilityService->shouldReceive('candidateServiceBayIds')->once()->andReturn(null);
    $availabilityService->shouldReceive('indexAppointment')->once();

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertCreated();

    expect(Appointment::query()->count())->toBe(1);
})->with('booking');

it('updates the Redis availability index only after the transaction has committed', function (object $booking) {
    $baselineLevel = DB::transactionLevel();
    $seenAtIndexing = null;

    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')->with($booking->dealership->id)->andReturn(null);

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->andReturn(null);
    $availabilityService->shouldReceive('candidateServiceBayIds')->andReturn(null);
    $availabilityService->shouldReceive('indexAppointment')
        ->once()
        ->andReturnUsing(function () use (&$seenAtIndexing) {
            // Recorded, not asserted here: the app may swallow exceptions from Redis calls.
            $seenAtIndexing = [
                'transaction_level' => DB::transactionLevel(),
                'appointments' => Appointment::query()->count(),
            ];
        });

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertCreated();

    expect($seenAtIndexing)->toBe([
        'transaction_level' => $baselineLevel,
        'appointments' => 1,
    ]);
})->with('booking');

it('keeps the database authoritative when the Redis index wrongly reports a busy resource as free', function (object $booking) {
    existingAppointment($booking);

    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')->with($booking->dealership->id)->andReturn(null);

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->andReturn([$booking->technician->id]);
    $availabilityService->shouldReceive('candidateServiceBayIds')->andReturn([$booking->serviceBay->id]);
    $availabilityService->shouldNotReceive('indexAppointment');

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect(Appointment::query()->count())->toBe(1);
})->with('booking');

/*
|--------------------------------------------------------------------------
| Double booking (sequential; true parallelism is the todo() items below)
|--------------------------------------------------------------------------
*/

it('does not let a second customer reserve a resource that is already booked', function (object $booking, string $freeResource) {
    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertCreated();

    // Only one resource kind stays contended: the other one has a free spare.
    $freeResource::factory()->for($booking->dealership)->create(['active' => true]);

    $otherCustomer = Customer::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCustomer)->create();
    Sanctum::actingAs($otherCustomer);

    $this->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-01T10:30:00+07:00', [
        'vehicle_id' => $otherVehicle->id,
    ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect(Appointment::query()->where('status', 'confirmed')->where('technician_id', $booking->technician->id)->count())->toBe(1)
        ->and(Appointment::query()->where('status', 'confirmed')->where('service_bay_id', $booking->serviceBay->id)->count())->toBe(1)
        ->and(Appointment::query()->count())->toBe(1);
})->with('booking')->with([
    'technician contended' => [ServiceBay::class],
    'bay contended' => [Technician::class],
]);

it('does not make different dealerships conflict and locks each dealership separately', function (object $booking) {
    $otherDealership = Dealership::factory()->create();
    $otherTechnician = Technician::factory()->for($otherDealership)->create(['active' => true]);
    $otherBay = ServiceBay::factory()->for($otherDealership)->create(['active' => true]);
    $secondVehicle = Vehicle::factory()->for($booking->customer)->create();

    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')->once()->with($booking->dealership->id)->andReturn(null);
    $lockService->shouldReceive('acquire')->once()->with($otherDealership->id)->andReturn(null);

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->twice()->andReturn(null);
    $availabilityService->shouldReceive('candidateServiceBayIds')->twice()->andReturn(null);
    $availabilityService->shouldReceive('indexAppointment')->twice();

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $booking->technician->id);

    $this->postJson('/api/v1/appointments', bookingPayload($booking, overrides: [
        'vehicle_id' => $secondVehicle->id,
        'dealership_id' => $otherDealership->id,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.technician.id', $otherTechnician->id)
        ->assertJsonPath('data.service_bay.id', $otherBay->id);

    expect(Appointment::query()->count())->toBe(2);
})->with('booking');

// Need real parallel requests on MySQL/PostgreSQL with a non-transactional DB trait
// (SQLite ignores lockForUpdate(), see Phase 9).
it('never double books the same technician under parallel requests')->todo();
it('never double books the same service bay under parallel requests')->todo();
it('serialises parallel bookings for the same dealership')->todo();
