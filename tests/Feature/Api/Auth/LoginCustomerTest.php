<?php

use App\Models\Customer;

it('logs a customer in', function () {
    Customer::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.customer.email', 'customer@example.com')
        ->assertJsonPath('data.customer.first_name', 'John')
        ->assertJsonPath('data.customer.last_name', 'Doe')
        ->assertJsonStructure(['data' => ['customer' => ['id', 'first_name', 'last_name', 'email'], 'token']]);
});

it('validates the login request', function () {
    $this->postJson('/api/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects invalid customer credentials', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();
});
