<?php

namespace App\DTOs\Auth;

final readonly class LoginCustomerData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
