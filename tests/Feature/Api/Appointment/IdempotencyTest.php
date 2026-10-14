<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\IdempotencyKey;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Replay behaviour
|--------------------------------------------------------------------------
*/

it('creates one appointment for a new key and returns 201', function (object $booking) {
    $this->withHeader('Idempotency-Key', 'booking-001')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    $record = IdempotencyKey::query()->firstOrFail();

    expect(Appointment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and($record->customer_id)->toBe($booking->customer->id)
        ->and($record->appointment_id)->toBe(Appointment::query()->firstOrFail()->id)
        ->and($record->response_status)->toBe(201);
})->with('booking');

it('replays the original appointment with 200 for the same key and request', function (object $booking) {
    $payload = bookingPayload($booking);

    $first = $this->withHeader('Idempotency-Key', 'booking-002')
        ->postJson('/api/v1/appointments', $payload)
        ->assertCreated();

    $second = $this->withHeader('Idempotency-Key', 'booking-002')
        ->postJson('/api/v1/appointments', $payload)
        ->assertOk()
        ->assertExactJson($first->json())
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.technician.id', $booking->technician->id)
        ->assertJsonPath('data.service_bay.id', $booking->serviceBay->id);

    foreach (internalAppointmentFields() as $field) {
        $second->assertJsonMissingPath("data.{$field}");
    }

    expect(Appointment::query()->count())->toBe(1);
})->with('booking');

it('treats the same instant in a different UTC offset as the same request', function (object $booking) {
    $first = $this->withHeader('Idempotency-Key', 'booking-offset')
        ->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-01T10:00:00+07:00'))
        ->assertCreated();

    $this->withHeader('Idempotency-Key', 'booking-offset')
        ->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-01T03:00:00+00:00'))
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Appointment::query()->count())->toBe(1);
})->with('booking');

it('rejects the same key with a different request', function (object $booking, string $field) {
    $this->withHeader('Idempotency-Key', 'booking-003')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    $changed = match ($field) {
        'vehicle_id' => Vehicle::factory()->for($booking->customer)->create()->id,
        'service_type_id' => ServiceType::factory()->create(['duration' => 60, 'active' => true])->id,
        'dealership_id' => tap(Dealership::factory()->create(), function (Dealership $dealership) {
            Technician::factory()->for($dealership)->create(['active' => true]);
            ServiceBay::factory()->for($dealership)->create(['active' => true]);
        })->id,
        'start_at' => '2030-11-01T11:00:00+07:00',
    };

    $this->withHeader('Idempotency-Key', 'booking-003')
        ->postJson('/api/v1/appointments', bookingPayload($booking, overrides: [$field => $changed]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_MISMATCH');

    expect(Appointment::query()->count())->toBe(1);
})->with('booking')->with(['vehicle_id', 'dealership_id', 'service_type_id', 'start_at']);

/*
|--------------------------------------------------------------------------
| Key scoping
|--------------------------------------------------------------------------
*/

it('scopes keys to the authenticated customer', function (object $booking) {
    $this->withHeader('Idempotency-Key', 'customer-scoped-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    $otherCustomer = Customer::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCustomer)->create();
    Sanctum::actingAs($otherCustomer);

    // Different slot, because the dealership only has one technician and bay.
    $this->withHeader('Idempotency-Key', 'customer-scoped-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-01T11:00:00+07:00', [
            'vehicle_id' => $otherVehicle->id,
        ]))
        ->assertCreated();

    expect(Appointment::query()->count())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(2);
})->with('booking');

it('treats different keys as separate booking attempts', function (object $booking) {
    $this->withHeader('Idempotency-Key', 'key-a')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    // Same slot under a new key is a new attempt (not a replay), so it conflicts.
    $this->withHeader('Idempotency-Key', 'key-b')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    // A free slot under yet another key creates a second appointment.
    $this->withHeader('Idempotency-Key', 'key-c')
        ->postJson('/api/v1/appointments', bookingPayload($booking, '2030-11-01T12:00:00+07:00'))
        ->assertCreated();

    expect(Appointment::query()->count())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(2);
})->with('booking');

/*
|--------------------------------------------------------------------------
| Retention and expiry
|--------------------------------------------------------------------------
*/

it('retains keys for 24 hours', function (object $booking) {
    CarbonImmutable::setTestNow($now = utcTime('2030-10-01T10:00:00+00:00'));

    $first = $this->withHeader('Idempotency-Key', 'retention-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    expect(IdempotencyKey::query()->firstOrFail()->expires_at->utc()->equalTo($now->addHours(24)))->toBeTrue();

    CarbonImmutable::setTestNow($now->addHours(23)->addMinutes(59));

    $this->withHeader('Idempotency-Key', 'retention-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));
})->with('booking');

it('lets an expired key be reused', function (object $booking) {
    IdempotencyKey::factory()->create([
        'customer_id' => $booking->customer->id,
        'key' => 'expired-key',
        'request_fingerprint' => 'old-fingerprint',
        'expires_at' => CarbonImmutable::now()->subMinute(),
    ]);

    $this->withHeader('Idempotency-Key', 'expired-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    $records = IdempotencyKey::query()->where('key', 'expired-key');

    expect(Appointment::query()->count())->toBe(1)
        ->and($records->count())->toBe(1)
        ->and($records->first()->expires_at->isFuture())->toBeTrue();
})->with('booking');

/*
|--------------------------------------------------------------------------
| Failed bookings leave no idempotency state
|--------------------------------------------------------------------------
*/

it('leaves no appointment or idempotency record when persistence fails, and the key can be reused', function (object $booking) {
    $shouldFail = true;

    // Fails AFTER the appointment row has really been inserted, so this proves the rollback.
    Appointment::created(function () use (&$shouldFail) {
        if ($shouldFail) {
            throw new RuntimeException('simulated failure after insert');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader('Idempotency-Key', 'retry-after-failure')
        ->postJson('/api/v1/appointments', bookingPayload($booking)))
        ->toThrow(RuntimeException::class);

    expect(Appointment::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);

    $shouldFail = false;

    $this->withHeader('Idempotency-Key', 'retry-after-failure')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    expect(Appointment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1);
})->with('booking');

it('leaves no idempotency record when no resource is available, and the key can be reused once one frees up', function (object $booking) {
    existingAppointment($booking);

    $this->withHeader('Idempotency-Key', 'conflict-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect(Appointment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(0);

    Appointment::query()->update(['status' => 'cancelled']);

    $this->withHeader('Idempotency-Key', 'conflict-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated();

    expect(IdempotencyKey::query()->count())->toBe(1);
})->with('booking');

/*
|--------------------------------------------------------------------------
| Persistence guarantees
|--------------------------------------------------------------------------
*/

it('enforces database uniqueness for customer and key', function (object $booking) {
    IdempotencyKey::factory()->create([
        'customer_id' => $booking->customer->id,
        'key' => 'unique-key',
    ]);

    expect(fn () => IdempotencyKey::factory()->create([
        'customer_id' => $booking->customer->id,
        'key' => 'unique-key',
    ]))->toThrow(QueryException::class);
})->with('booking');

it('fingerprints exactly the documented request fields', function (object $booking) {
    $payload = bookingPayload($booking);

    $this->withHeader('Idempotency-Key', 'fingerprint-key')
        ->postJson('/api/v1/appointments', $payload)
        ->assertCreated();

    $expected = hash('sha256', json_encode([
        'vehicle_id' => $booking->vehicle->id,
        'dealership_id' => $booking->dealership->id,
        'service_type_id' => $booking->serviceType->id,
        'start_at' => CarbonImmutable::parse($payload['start_at'])->utc()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));

    expect(IdempotencyKey::query()->firstOrFail()->request_fingerprint)->toBe($expected);
})->with('booking');

// Needs real parallel requests on MySQL/PostgreSQL (see Phase 9). Until then the
// DB unique constraint tested above is the guard.
it('prevents duplicates when the same key is submitted in parallel')->todo();
