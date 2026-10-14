<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use App\DTOs\Appointment\CreateAppointmentData;
use Carbon\CarbonImmutable;

final class AppointmentService
{
    public function create(
        CreateAppointmentData $data,
        Vehicle $vehicle,
        Dealership $dealership,
        ServiceType $serviceType,
        Technician $technician,
        ServiceBay $serviceBay,
        CarbonImmutable $endAt,
    ): Appointment {
        return Appointment::create([
            'customer_id' => $data->customerId,
            'vehicle_id' => $vehicle->getKey(),
            'dealership_id' => $dealership->getKey(),
            'service_type_id' => $serviceType->getKey(),
            'technician_id' => $technician->getKey(),
            'service_bay_id' => $serviceBay->getKey(),
            'start_at' => $data->startAt,
            'end_at' => $endAt,
            'status' => 'confirmed',
        ]);
    }

    public function find(string $appointmentId): ?Appointment
    {
        return Appointment::find($appointmentId);
    }

    public function cancel(Appointment $appointment): Appointment
    {
        $appointment->update(['status' => 'cancelled']);

        return $appointment->refresh();
    }
}
