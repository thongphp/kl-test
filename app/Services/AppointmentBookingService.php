<?php

namespace App\Services;

use App\DTOs\Appointment\AppointmentBookingResult;
use App\DTOs\Appointment\CreateAppointmentData;
use App\Exceptions\ResourceConflictException;
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
        private readonly IdempotencyService $idempotencyService,
    ) {}

    public function book(CreateAppointmentData $data, ?string $idempotencyKey = null): AppointmentBookingResult
    {
        $fingerprint = $this->idempotencyService->fingerprint($data);
        $lock = $this->redisBookingLockService->acquire($data->dealershipId);

        try {
            $result = DB::transaction(function () use ($data, $idempotencyKey, $fingerprint): AppointmentBookingResult {
                $dealership = $this->dealershipService->lockActive($data->dealershipId);

                $customer = Customer::query()->findOrFail($data->customerId);
                $idempotency = null;

                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $idempotency = $this->idempotencyService->begin(
                        customerId: $customer->getKey(),
                        key: $idempotencyKey,
                        fingerprint: $fingerprint,
                    );

                    if ($idempotency['replay']) {
                        return new AppointmentBookingResult(
                            appointment: $this->idempotencyService->appointment($idempotency['record']),
                            replayed: true,
                        );
                    }
                }

                $vehicle = $this->customerService->findVehicleForBooking($customer, $data->vehicleId);
                $serviceType = $this->serviceTypeService->findActive($data->serviceTypeId);
                $endAt = $data->startAt->addMinutes($serviceType->duration);

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

                // Return the persisted representation so the initial response matches
                // the representation returned by an idempotent replay.
                $appointment->refresh();

                if ($idempotency !== null) {
                    $this->idempotencyService->complete($idempotency['record'], $appointment);
                }

                return new AppointmentBookingResult(
                    appointment: $appointment,
                    replayed: false,
                );
            });

            if (! $result->replayed) {
                try {
                    $this->redisAvailabilityService->indexAppointment(
                        appointmentId: $result->appointment->getKey(),
                        technicianId: $result->appointment->technician_id,
                        serviceBayId: $result->appointment->service_bay_id,
                        dealershipId: $result->appointment->dealership_id,
                        startAt: $result->appointment->start_at,
                        endAt: $result->appointment->end_at,
                    );
                } catch (Throwable) {
                    // The DB transaction and idempotency record are already committed.
                }
            }

            return $result;
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
