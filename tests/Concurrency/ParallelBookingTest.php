<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\ServiceBay;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Concurrency;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Fire every attempt as its own PHP process. All processes sleep until the same
 * wall-clock instant, then call the real HTTP kernel, so requests truly overlap
 * even though each process boots at a different speed.
 *
 * @param  array<int, array{token: string, payload: array, key?: string|null}>  $attempts
 * @return array<int, array{status: int, body: array|null}>
 */
function fireParallelBookings(array $attempts): array
{
    $startAt = microtime(true) + (float) env('PARALLEL_BARRIER_SECONDS', 5.0);

			$tasks = [];

			foreach ($attempts as $attemp) {
					$tasks[] = static function () use ($attempt, $startAt) {
            $wait = $startAt - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            $server = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$attempt['token'],
            ];

            if (! empty($attempt['key'])) {
                $server['HTTP_IDEMPOTENCY_KEY'] = $attempt['key'];
            }

            $request = Request::create(
                '/api/v1/appointments', 'POST', [], [], [], $server, json_encode($attempt['payload']),
            );

            $response = app(Kernel::class)->handle($request);

            return [
                'status' => $response->getStatusCode(),
                'body' => json_decode($response->getContent(), true),
            ];
        };
			}

    return Concurrency::run($tasks);
}

/**
 * One new customer (own vehicle + real Sanctum token) per start time.
 *
 * @param  array<int, string>  $startTimes
 * @return array<int, array{customer: Customer, token: string, payload: array}>
 */
function contenders(object $booking, array $startTimes): array
{
    return array_map(function (string $startAt) use ($booking) {
        $customer = Customer::factory()->create();
        $vehicle = Vehicle::factory()->for($customer)->create();

        return [
            'customer' => $customer,
            'token' => $customer->createToken('parallel')->plainTextToken,
            'payload' => bookingPayload($booking, $startAt, ['vehicle_id' => $vehicle->id]),
        ];
    }, $startTimes);
}

function attemptsFrom(array $contenders): array
{
    return array_map(fn (array $c) => Arr::only($c, ['token', 'payload']), $contenders);
}

function assertNoOverlappingAppointments(string $resourceColumn): void
{
    $byResource = Appointment::query()
        ->where('status', 'confirmed')
        ->orderBy('start_at')
        ->get()
        ->groupBy($resourceColumn);

    foreach ($byResource as $appointments) {
        $list = $appointments->values();

        for ($i = 1; $i < $list->count(); $i++) {
            expect($list[$i]->start_at->greaterThanOrEqualTo($list[$i - 1]->end_at))
                ->toBeTrue();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Parallel booking
|--------------------------------------------------------------------------
*/

it('never books the same technician twice under parallel requests', function (object $booking) {
    // Spare bay, so ONLY the technician is contended.
    ServiceBay::factory()->for($booking->dealership)->create(['active' => true]);

    $results = collect(fireParallelBookings(attemptsFrom(
        contenders($booking, array_fill(0, 4, '2030-11-01T10:00:00+07:00')),
    )));

    expect($results->where('status', 201))->toHaveCount(1)
        ->and($results->where('status', '!=', 201)->pluck('status')->unique()->values()->all())->toBe([409]);

    $results->where('status', 409)->each(
        fn (array $r) => expect($r['body']['code'])->toBeIn(['NO_RESOURCE_AVAILABLE', 'BOOKING_IN_PROGRESS']),
    );

    expect(Appointment::query()->where('technician_id', $booking->technician->id)->count())->toBe(1)
        ->and(Appointment::query()->count())->toBe(1);

    assertNoOverlappingAppointments('technician_id');
})->with('booking');

it('never books the same service bay twice under parallel requests', function (object $booking) {
    // Spare technician, so ONLY the bay is contended.
    Technician::factory()->for($booking->dealership)->create(['active' => true]);

    $results = collect(fireParallelBookings(attemptsFrom(
        contenders($booking, array_fill(0, 4, '2030-11-01T10:00:00+07:00')),
    )));

    expect($results->where('status', 201))->toHaveCount(1)
        ->and($results->where('status', '!=', 201)->pluck('status')->unique()->values()->all())->toBe([409]);

    $results->where('status', 409)->each(
        fn (array $r) => expect($r['body']['code'])->toBeIn(['NO_RESOURCE_AVAILABLE', 'BOOKING_IN_PROGRESS']),
    );

    expect(Appointment::query()->where('service_bay_id', $booking->serviceBay->id)->count())->toBe(1)
        ->and(Appointment::query()->count())->toBe(1);

    assertNoOverlappingAppointments('service_bay_id');
})->with('booking');

it('serialises parallel bookings for the same dealership without corrupting state', function (object $booking) {
    $contenders = contenders($booking, [
        '2030-11-01T08:00:00+07:00',
        '2030-11-01T10:00:00+07:00',
        '2030-11-01T12:00:00+07:00',
        '2030-11-01T14:00:00+07:00',
    ]);

    $results = fireParallelBookings(attemptsFrom($contenders));
    $statuses = collect($results)->pluck('status');

    // Slots never overlap, so the only legal loss is lock contention. Never a 5xx or 422.
    expect($statuses->every(fn (int $s) => in_array($s, [201, 409], true)))->toBeTrue()
        ->and($statuses->contains(201))->toBeTrue();

    collect($results)->where('status', 409)->each(
        fn (array $r) => expect($r['body']['code'])->toBe('BOOKING_IN_PROGRESS'),
    );

    // Lock must not leak: every loser can book right after.
    foreach ($results as $i => $result) {
        if ($result['status'] === 409) {
            Sanctum::actingAs($contenders[$i]['customer']);
            $this->postJson('/api/v1/appointments', $contenders[$i]['payload'])->assertCreated();
        }
    }

    expect(Appointment::query()->count())->toBe(4);

    assertNoOverlappingAppointments('technician_id');
    assertNoOverlappingAppointments('service_bay_id');
})->with('booking');

it('creates exactly one appointment for parallel requests with the same idempotency key', function (object $booking) {
    $token = $booking->customer->createToken('parallel')->plainTextToken;
    $attempt = ['token' => $token, 'payload' => bookingPayload($booking), 'key' => 'parallel-key'];

    $results = collect(fireParallelBookings(array_fill(0, 4, $attempt)));

    expect($results->where('status', 201))->toHaveCount(1);

    $createdId = $results->firstWhere('status', 201)['body']['data']['id'];

    // Everyone else is a replay of the same appointment, or lock contention. Never a resource conflict.
    $results->where('status', '!=', 201)->each(function (array $r) use ($createdId) {
        expect($r['status'])->toBeIn([200, 409]);

        if ($r['status'] === 200) {
            expect($r['body']['data']['id'])->toBe($createdId);
        } else {
            expect($r['body']['code'])->toBe('BOOKING_IN_PROGRESS');
        }
    });

    expect(Appointment::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->count())->toBe(1);

    // Record was committed: a later retry replays.
    $this->withHeader('Idempotency-Key', 'parallel-key')
        ->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertOk()
        ->assertJsonPath('data.id', $createdId);
})->with('booking');
