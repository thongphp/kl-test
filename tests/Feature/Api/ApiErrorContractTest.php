<?php

use App\Models\Customer;
use App\Services\AppointmentService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Business error codes live next to the rules that raise them:
|   DEALERSHIP_INACTIVE, SERVICE_TYPE_INACTIVE, SERVICE_TYPE_INVALID_DURATION -> CreateAppointmentTest
|   NO_RESOURCE_AVAILABLE                                                     -> ResourceAllocationTest
|   BOOKING_IN_PROGRESS                                                       -> BookingReliabilityTest
|   APPOINTMENT_CANNOT_BE_CANCELLED, FORBIDDEN                                -> AppointmentLifecycleTest
|   IDEMPOTENCY_KEY_MISMATCH                                                  -> IdempotencyTest
|
| This file covers the cross-cutting HTTP contract.
|--------------------------------------------------------------------------
*/

it('returns 401 on every endpoint when unauthenticated', function (string $method, string $uri) {
    $this->json($method, str_replace('{id}', (string) Str::uuid(), $uri))
        ->assertUnauthorized();
})->with([
    'create' => ['POST', '/api/v1/appointments'],
    'list' => ['GET', '/api/v1/appointments'],
    'detail' => ['GET', '/api/v1/appointments/{id}'],
    'cancel' => ['POST', '/api/v1/appointments/{id}/cancel'],
]);

it('returns 404 for an unknown appointment', function (string $method, string $uri) {
    Sanctum::actingAs(Customer::factory()->create());

    $this->json($method, str_replace('{id}', (string) Str::uuid(), $uri))
        ->assertNotFound();
})->with([
    'detail' => ['GET', '/api/v1/appointments/{id}'],
    'cancel' => ['POST', '/api/v1/appointments/{id}/cancel'],
]);

it('returns 400 BAD_REQUEST for a malformed JSON body', function () {
    Sanctum::actingAs(Customer::factory()->create());

    $this->call('POST', '/api/v1/appointments', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], '{"vehicle_id": ')
        ->assertStatus(400)
        ->assertJsonPath('code', 'BAD_REQUEST');
});

it('keeps the Laravel validation format without leaking internals', function () {
    Sanctum::actingAs(Customer::factory()->create());

    $this->postJson('/api/v1/appointments', [])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');
});

it('does not reveal technician or bay details when no resource is available', function (object $booking) {
    $booking->technician->update(['active' => false]);

    $response = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');

    expect($response->getContent())
        ->not->toContain($booking->technician->id)
        ->not->toContain($booking->serviceBay->id);
})->with('booking');

it('does not leak exception details when an unexpected error occurs', function (object $booking) {
    config(['app.debug' => false]);

    $appointmentService = Mockery::mock(AppointmentService::class);
    $appointmentService->shouldReceive('create')
        ->once()
        ->andThrow(new RuntimeException('simulated internal failure'));

    $this->app->instance(AppointmentService::class, $appointmentService);

    $response = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(500)
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace')
        ->assertJsonMissingPath('file')
        ->assertJsonMissingPath('line');

    expect($response->getContent())->not->toContain('simulated internal failure');
})->with('booking');
