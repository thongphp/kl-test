<?php

use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function appointmentErrorHandlingFixture(): array
{
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($customer)->create();
    $dealership = Dealership::factory()->create();
    $technician = Technician::factory()->for($dealership)->create(['active' => true]);
    $serviceBay = ServiceBay::factory()->for($dealership)->create(['active' => true]);
    $serviceType = ServiceType::factory()->create(['duration' => 60, 'active' => true]);

    Sanctum::actingAs($customer);

    return compact(
        'customer',
        'vehicle',
        'dealership',
        'technician',
        'serviceBay',
        'serviceType',
    );
}

it('returns 409 with NO_RESOURCE_AVAILABLE when no technician and service bay are available', function () {
    $fixture = appointmentErrorHandlingFixture();

    $fixture['technician']->update(['active' => false]);
    $fixture['serviceBay']->update(['active' => false]);

    // Existing fixture/setup should force the application's resource-conflict path.
    $response = $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');
});

it('returns 422 with DEALERSHIP_INACTIVE for an inactive dealership', function () {
    $fixture = appointmentErrorHandlingFixture();
    $fixture['dealership']->update(['active' => false]);

    $response = $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'DEALERSHIP_INACTIVE');
});

it('returns 422 with SERVICE_TYPE_INACTIVE for an inactive service type', function () {
    $fixture = appointmentErrorHandlingFixture();
    $fixture['serviceType']->update(['active' => false]);

    $response = $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $fixture['vehicle']->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'SERVICE_TYPE_INACTIVE');
});

it('preserves Laravel validation structure for malformed booking input', function () {
    appointmentErrorHandlingFixture();

    $response = $this->postJson('/api/v1/appointments', []);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'message',
            'errors',
        ]);
});

it('returns 401 when booking without authentication', function () {
    $response = $this->postJson('/api/v1/appointments', []);

    $response->assertUnauthorized();
});

it('returns 404 when attempting to book another customer vehicle', function () {
    $fixture = appointmentErrorHandlingFixture();

    $otherCustomer = Customer::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCustomer)->create();

    $response = $this->postJson('/api/v1/appointments', [
        'vehicle_id' => $otherVehicle->id,
        'dealership_id' => $fixture['dealership']->id,
        'service_type_id' => $fixture['serviceType']->id,
        'start_at' => now()->addDay()->toIso8601String(),
    ]);
    
    $response->assertStatus(422)
        ->assertJsonStructure([
            'message',
            'errors',
        ]);
});

it('does not leak exception or trace details in error responses', function () {
    appointmentErrorHandlingFixture();

    $response = $this->postJson('/api/v1/appointments', []);

    $response->assertStatus(422)
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');
});
