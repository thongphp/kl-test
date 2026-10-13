<?php

namespace App\Services\Auth;

use App\DTOs\Auth\AuthenticatedCustomerData;
use App\DTOs\Auth\LoginCustomerData;
use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class CustomerAuthenticationService
{
    public function authenticate(LoginCustomerData $data): AuthenticatedCustomerData
    {
        $customer = Customer::query()->where('email', $data->email)->first();

        if (! $customer || ! Hash::check($data->password, $customer->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $customer->createToken('api')->plainTextToken;

        return new AuthenticatedCustomerData($customer, $token);
    }
}
