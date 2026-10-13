<?php

use App\DTOs\Auth\LoginCustomerData;
use App\Models\Customer;
use App\Services\Auth\CustomerAuthenticationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

it('authenticates a customer and returns a token', function () {
    $customer = Customer::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    $result = app(CustomerAuthenticationService::class)->authenticate(
        new LoginCustomerData('customer@example.com', 'password')
    );

    expect($result->customer->is($customer))->toBeTrue()
        ->and($result->token)->toBeString()->not->toBeEmpty();
});

it('rejects invalid credentials', function () {
    Customer::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    expect(fn () => app(CustomerAuthenticationService::class)->authenticate(
        new LoginCustomerData('customer@example.com', 'wrong-password')
    ))->toThrow(ValidationException::class);
});
