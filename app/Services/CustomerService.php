<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

final class CustomerService
{
    public function findVehicleForBooking(Customer $customer, string $vehicleId): Vehicle
    {
        $vehicle = Vehicle::withTrashed()->find($vehicleId);

        if ($vehicle === null) {
            throw ValidationException::withMessages(['vehicle_id' => 'The selected vehicle does not exist.']);
        }

        if ($vehicle->customer_id !== $customer->getKey()) {
            throw ValidationException::withMessages(['vehicle_id' => 'The selected vehicle does not belong to the authenticated customer.']);
        }

        if ($vehicle->trashed() || $vehicle->status !== 'available') {
            throw ValidationException::withMessages(['vehicle_id' => 'The selected vehicle is unavailable.']);
        }

        return $vehicle;
    }
}
