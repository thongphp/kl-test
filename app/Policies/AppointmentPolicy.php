<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Customer;

final class AppointmentPolicy
{
    public function view(Customer $customer, Appointment $appointment): bool
    {
        return $appointment->customer_id === $customer->getKey();
    }

    public function cancel(Customer $customer, Appointment $appointment): bool
    {
        return $appointment->customer_id === $customer->getKey();
    }
}
