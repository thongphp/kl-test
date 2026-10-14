<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Policies\AppointmentPolicy;
use App\Policies\VehiclePolicy;

it('allows a customer to view their own vehicle', function () {
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->create(['customer_id' => $customer->id]);

    expect((new VehiclePolicy)->view($customer, $vehicle))->toBeTrue();
});

it('denies a customer access to another customer vehicle', function () {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->create(['customer_id' => $otherCustomer->id]);

    expect((new VehiclePolicy)->view($customer, $vehicle))->toBeFalse();
});

it('allows a customer to view and cancel their own appointment', function () {
    $customer = Customer::factory()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $customer->id]);

    $policy = new AppointmentPolicy;

    expect($policy->view($customer, $appointment))->toBeTrue()
        ->and($policy->cancel($customer, $appointment))->toBeTrue();
});

it('denies a customer access to another customer appointment', function () {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $appointment = Appointment::factory()->create(['customer_id' => $otherCustomer->id]);

    $policy = new AppointmentPolicy;

    expect($policy->view($customer, $appointment))->toBeFalse()
        ->and($policy->cancel($customer, $appointment))->toBeFalse();
});
