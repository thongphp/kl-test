<?php

namespace App\DTOs\Auth;

use App\Models\Customer;

final readonly class AuthenticatedCustomerData
{
    public function __construct(
        public Customer $customer,
        public string $token,
    ) {}
}
