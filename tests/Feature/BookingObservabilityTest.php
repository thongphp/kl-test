<?php

use App\Exceptions\BookingInProgressException;
use App\Services\AppointmentService;
use App\Services\RedisAvailabilityService;
use App\Services\RedisBookingLockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Context of the log call `$level($event, ...)` captured by Log::spy().
 * Fails the test if no such call happened.
 */
function loggedContext(string $level, string $event): array
{
    $captured = null;

    Log::shouldHaveReceived($level)->withArgs(function ($message, $context = []) use ($event, &$captured) {
        if ($message === $event) {
            $captured = $context;

            return true;
        }

        return false;
    });

    return $captured ?? [];
}

it('logs lock contention with identifiers only', function (object $booking) {
    Log::spy();

    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')->once()->andThrow(new BookingInProgressException());
    $this->app->instance(RedisBookingLockService::class, $lockService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertStatus(409);

    expect(loggedContext('warning', 'booking.lock_contention'))->toMatchArray([
        'customer_id' => $booking->customer->id,
        'dealership_id' => $booking->dealership->id,
        'service_type_id' => $booking->serviceType->id,
        'exception_class' => BookingInProgressException::class,
    ]);
})->with('booking');

it('logs resource conflicts', function (object $booking) {
    Log::spy();
    existingAppointment($booking);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertStatus(409);

    expect(loggedContext('info', 'booking.resource_conflict'))->toMatchArray([
        'customer_id' => $booking->customer->id,
        'dealership_id' => $booking->dealership->id,
        'start_at' => '2030-11-01T10:00:00+07:00',
    ]);
})->with('booking');

it('logs idempotency mismatches without leaking the key', function (object $booking) {
    Log::spy();

    $this->withHeader('Idempotency-Key', 'secret-key-123')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    $this->withHeader('Idempotency-Key', 'secret-key-123')
        ->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-01T12:00:00+07:00'))
        ->assertUnprocessable();

    $context = loggedContext('warning', 'booking.idempotency_mismatch');

    expect($context)->toMatchArray(['customer_id' => $booking->customer->id, 'idempotent' => true])
        ->and(json_encode($context))->not->toContain('secret-key-123');
})->with('booking');

it('logs cancellation conflicts with the appointment id', function (object $booking) {
    Log::spy();
    CarbonImmutable::setTestNow(utcTime('2030-10-01T10:00:00+07:00'));

    $appointment = existingAppointment($booking, ['status' => 'cancelled']);

    $this->postJson('/api/v1/appointments/'.$appointment->id.'/cancel')->assertStatus(409);

    expect(loggedContext('info', 'booking.cancellation_conflict'))->toMatchArray([
        'customer_id' => $booking->customer->id,
        'appointment_id' => $appointment->id,
    ]);
})->with('booking');

it('logs unexpected booking failures as errors with the exception', function (object $booking) {
    config(['app.debug' => false]);
    Log::spy();

    $appointmentService = Mockery::mock(AppointmentService::class);
    $appointmentService->shouldReceive('create')->once()->andThrow(new RuntimeException('simulated internal failure'));
    $this->app->instance(AppointmentService::class, $appointmentService);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertStatus(500);

    $context = loggedContext('error', 'booking.failed');

    expect($context['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($context)->toMatchArray(['dealership_id' => $booking->dealership->id]);
})->with('booking');

it('does not log expected business rejections as errors', function (object $booking) {
    Log::spy();
    $booking->dealership->update(['active' => false]);

    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertUnprocessable();

    Log::shouldNotHaveReceived('error');
})->with('booking');

it('logs and falls back when Redis cannot take the dealership lock', function () {
    Log::spy();
    Cache::shouldReceive('store')->with('redis')->andThrow(new RuntimeException('redis down'));

    $dealershipId = (string) Str::uuid();

    expect(app(RedisBookingLockService::class)->acquire($dealershipId))->toBeNull()
        ->and(loggedContext('warning', 'booking.redis_unavailable'))->toMatchArray([
            'dealership_id' => $dealershipId,
            'exception_class' => RuntimeException::class,
        ]);
});

it('logs a failed Redis index update but still books', function (object $booking) {
    Log::spy();

    $lockService = Mockery::mock(RedisBookingLockService::class);
    $lockService->shouldReceive('acquire')->once()->andReturn(null);

    $availabilityService = Mockery::mock(RedisAvailabilityService::class);
    $availabilityService->shouldReceive('candidateTechnicianIds')->andReturn(null);
    $availabilityService->shouldReceive('candidateServiceBayIds')->andReturn(null);
    $availabilityService->shouldReceive('indexAppointment')->once()->andThrow(new RuntimeException('redis down'));

    $this->app->instance(RedisBookingLockService::class, $lockService);
    $this->app->instance(RedisAvailabilityService::class, $availabilityService);

    $response = $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertCreated();

    expect(loggedContext('warning', 'booking.redis_index_failed'))->toMatchArray([
        'appointment_id' => $response->json('data.id'),
        'dealership_id' => $booking->dealership->id,
    ]);
})->with('booking');
