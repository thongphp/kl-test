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

function idempotencyFixture(): array
{
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($customer)->create();
    $dealership = Dealership::factory()->create();
    Technician::factory()->for($dealership)->create(['active' => true]);
    ServiceBay::factory()->for($dealership)->create(['active' => true]);
    $serviceType = ServiceType::factory()->create(['duration' => 60, 'active' => true]);

    Sanctum::actingAs($customer);

    return compact('customer', 'vehicle', 'dealership', 'serviceType');
}

function idempotencyPayload(array $fixture, string $startAt = '2026-11-01T10:00:00+07:00'): array
{
    return [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => $startAt,
    ];
}

test('new idempotency key creates one appointment and returns 201', function () {
    $fixture = idempotencyFixture();

    $response = $this->withHeader('Idempotency-Key', 'booking-001')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture));

    $response->assertCreated();

    expect(Appointment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->first()->appointment_id)->not->toBeNull()
        ->and(IdempotencyKey::query()->first()->response_status)->toBe(201);
});

test('same key and same request returns the original appointment with 200', function () {
    $fixture = idempotencyFixture();
    $payload = idempotencyPayload($fixture);

    $first = $this->withHeader('Idempotency-Key', 'booking-002')
        ->postJson('/api/v1/appointments', $payload);
    $appointmentId = $first->json('data.id');

    $second = $this->withHeader('Idempotency-Key', 'booking-002')
        ->postJson('/api/v1/appointments', $payload);

    $second->assertOk()
        ->assertJsonPath('data.id', $appointmentId)
        ->assertExactJson($first->json());

    expect(Appointment::query()->count())->toBe(1);
});

test('same key with a different request is rejected with 422', function () {
    $fixture = idempotencyFixture();

    $this->withHeader('Idempotency-Key', 'booking-003')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture, '2026-11-01T10:00:00+07:00'))
        ->assertCreated();

    $this->withHeader('Idempotency-Key', 'booking-003')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture, '2026-11-01T11:00:00+07:00'))
        ->assertUnprocessable();

    expect(Appointment::query()->count())->toBe(1);
});

test('idempotency keys are scoped to the authenticated customer', function () {
    $first = idempotencyFixture();

    $this->withHeader('Idempotency-Key', 'customer-scoped-key')
        ->postJson('/api/v1/appointments', idempotencyPayload($first))
        ->assertCreated();

    $second = idempotencyFixture();

    $this->withHeader('Idempotency-Key', 'customer-scoped-key')
        ->postJson('/api/v1/appointments', idempotencyPayload($second, '2026-11-01T11:00:00+07:00'))
        ->assertCreated();

    expect(Appointment::query()->count())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(2);
});

test('expired idempotency key can be reused', function () {
    $fixture = idempotencyFixture();

    IdempotencyKey::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'key' => 'expired-key',
        'request_fingerprint' => 'old-fingerprint',
        'expires_at' => CarbonImmutable::now()->subMinute(),
    ]);

    $this->withHeader('Idempotency-Key', 'expired-key')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture))
        ->assertCreated();

    expect(Appointment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->where('key', 'expired-key')->count())->toBe(1)
        ->and(IdempotencyKey::query()->where('key', 'expired-key')->first()->expires_at->isFuture())->toBeTrue();
});

test('failed booking does not leave a successful idempotency record', function () {
    $fixture = idempotencyFixture();

    $appointmentService = Mockery::mock(\App\Services\AppointmentService::class);
    $appointmentService->shouldReceive('create')
        ->once()
        ->andThrow(new RuntimeException('simulated persistence failure'));

    $this->app->instance(\App\Services\AppointmentService::class, $appointmentService);
    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader('Idempotency-Key', 'failed-booking')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture)))
        ->toThrow(RuntimeException::class);

    expect(Appointment::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

test('a failed booking can reuse the same idempotency key', function () {
    $fixture = idempotencyFixture();

    $appointmentService = Mockery::mock(\App\Services\AppointmentService::class);
    $appointmentService->shouldReceive('create')
        ->once()
        ->andThrow(new RuntimeException('simulated persistence failure'));

    $this->app->instance(\App\Services\AppointmentService::class, $appointmentService);
    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader('Idempotency-Key', 'retry-after-failure')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture)))
        ->toThrow(RuntimeException::class);

    $this->app->forgetInstance(\App\Services\AppointmentService::class);

    $this->withHeader('Idempotency-Key', 'retry-after-failure')
        ->postJson('/api/v1/appointments', idempotencyPayload($fixture))
        ->assertCreated();
});

test('database uniqueness prevents duplicate customer and key records', function () {
    $fixture = idempotencyFixture();

    IdempotencyKey::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'key' => 'unique-key',
    ]);

    expect(fn () => IdempotencyKey::factory()->create([
        'customer_id' => $fixture['customer']->id,
        'key' => 'unique-key',
    ]))->toThrow(QueryException::class);
});

it('uses the request fingerprint fields only', function () {
    $fixture = idempotencyFixture();
    $payload = idempotencyPayload($fixture);

    $this->withHeader('Idempotency-Key', 'fingerprint-key')
        ->postJson('/api/v1/appointments', $payload)
        ->assertCreated();

    $record = IdempotencyKey::query()->firstOrFail();

    $expected = hash('sha256', json_encode([
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => CarbonImmutable::parse($payload['start_at'])->utc()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));

    expect($record->request_fingerprint)->toBe($expected);
});
