<?php

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthenticatedCustomerData;
use App\DTOs\Auth\LoginCustomerData;
use App\Services\Auth\CustomerAuthenticationService;

final class LoginCustomerAction
{
    public function __construct(
        private readonly CustomerAuthenticationService $service,
    ) {}

    public function execute(LoginCustomerData $data): AuthenticatedCustomerData
    {
        return $this->service->authenticate($data);
    }
}
