<?php

namespace App\Services;

use App\DTOs\Appointment\CreateAppointmentData;
use App\Exceptions\ResourceConflictException;
use App\Models\Appointment;
use App\Models\Customer;

final class AppointmentBookingService
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly DealershipService $dealershipService,
        private readonly ServiceTypeService $serviceTypeService,
        private readonly TechnicianService $technicianService,
        private readonly ServiceBayService $serviceBayService,
        private readonly AppointmentService $appointmentService,
    ) {}

    public function book(CreateAppointmentData $data): Appointment
    {
        $customer = Customer::query()->findOrFail($data->customerId);
        $vehicle = $this->customerService->findVehicleForBooking($customer, $data->vehicleId);
        $dealership = $this->dealershipService->findActive($data->dealershipId);
        $serviceType = $this->serviceTypeService->findActive($data->serviceTypeId);
        $endAt = $data->startAt->addMinutes($serviceType->duration);

        $technician = $this->technicianService->findAvailable($dealership, $data->startAt, $endAt);
        $serviceBay = $this->serviceBayService->findAvailable($dealership, $data->startAt, $endAt);

        if ($technician === null || $serviceBay === null) {
            throw new ResourceConflictException();
        }

        return $this->appointmentService->create(
            data: $data,
            vehicle: $vehicle,
            dealership: $dealership,
            serviceType: $serviceType,
            technician: $technician,
            serviceBay: $serviceBay,
            endAt: $endAt,
        );
    }
}
