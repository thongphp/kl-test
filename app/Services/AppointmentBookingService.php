<?php

namespace App\Services;

use App\DTOs\Appointment\CreateAppointmentData;
use App\Exceptions\ResourceConflictException;
use App\Models\Appointment;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AppointmentBookingService
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly DealershipService $dealershipService,
        private readonly ServiceTypeService $serviceTypeService,
        private readonly TechnicianService $technicianService,
        private readonly ServiceBayService $serviceBayService,
        private readonly AppointmentService $appointmentService,
        private readonly RedisBookingLockService $redisBookingLockService,
        private readonly RedisAvailabilityService $redisAvailabilityService,
    ) {}

    public function book(CreateAppointmentData $data): Appointment
    {
        $lock = $this->redisBookingLockService->acquire($data->dealershipId);

        try {
            $appointment = DB::transaction(function () use ($data) {
                // Serializes bookings for the dealership in the authoritative database.
                $dealership = $this->dealershipService->lockActive($data->dealershipId);
                $customer = Customer::query()->findOrFail($data->customerId);
                $vehicle = $this->customerService->findVehicleForBooking($customer, $data->vehicleId);
                $serviceType = $this->serviceTypeService->findActive($data->serviceTypeId);
                $endAt = $data->startAt->addMinutes($serviceType->duration);

                // Redis is consulted first as an advisory availability index. Its answer never
                // replaces the authoritative DB overlap check below.
                $technicianIds = $this->redisAvailabilityService->candidateTechnicianIds(
                    $dealership,
                    $data->startAt,
                    $endAt,
                );
                $serviceBayIds = $this->redisAvailabilityService->candidateServiceBayIds(
                    $dealership,
                    $data->startAt,
                    $endAt,
                );

                $technician = $this->technicianService->findAvailable(
                    $dealership,
                    $data->startAt,
                    $endAt,
                    $technicianIds,
                );
                $serviceBay = $this->serviceBayService->findAvailable(
                    $dealership,
                    $data->startAt,
                    $endAt,
                    $serviceBayIds,
                );

                if ($technician === null || $serviceBay === null) {
                    throw new ResourceConflictException();
                }

                $appointment = $this->appointmentService->create(
                    data: $data,
                    vehicle: $vehicle,
                    dealership: $dealership,
                    serviceType: $serviceType,
                    technician: $technician,
                    serviceBay: $serviceBay,
                    endAt: $endAt,
                );

                return $appointment;
            });

            // Update Redis only after the DB transaction commits successfully.
            $this->redisAvailabilityService->indexAppointment(
                appointmentId: $appointment->getKey(),
                technicianId: $appointment->technician_id,
                serviceBayId: $appointment->service_bay_id,
                dealershipId: $appointment->dealership_id,
                startAt: $appointment->start_at,
                endAt: $appointment->end_at,
            );

            return $appointment;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The DB transaction has already established the authoritative result.
                }
            }
        }
    }
}
