<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Vehicle;

final class VehiclePolicy
{
    public function view(Customer $customer, Vehicle $vehicle): bool
    {
        return $vehicle->customer_id === $customer->getKey();
    }
}
