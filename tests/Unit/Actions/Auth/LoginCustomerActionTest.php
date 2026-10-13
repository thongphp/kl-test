<?php

use App\Actions\Auth\LoginCustomerAction;
use App\DTOs\Auth\LoginCustomerData;
use App\Models\Customer;
use App\Services\Auth\CustomerAuthenticationService;

it('delegates authentication to the customer authentication service', function () {
    $customer = Customer::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    $result = app(LoginCustomerAction::class)->execute(
        new LoginCustomerData('customer@example.com', 'password')
    );

    expect($result->customer->is($customer))->toBeTrue();
});
