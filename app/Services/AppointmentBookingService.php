<?php

namespace App\Services;

use App\DTOs\Appointment\AppointmentBookingResult;
use App\DTOs\Appointment\CreateAppointmentData;
use App\Exceptions\BookingInProgressException;
use App\Exceptions\ResourceConflictException;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AppointmentBookingService
{
    /** PostgreSQL: lock_not_available (lock_timeout) and deadlock_detected. */
    private const LOCK_WAIT_SQLSTATES = ['55P03', '40P01'];

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
                $this->limitLockWait();

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
                } catch (Throwable $exception) {
                    // The DB transaction and idempotency record are already committed.
                    Log::warning('booking.redis_index_failed', [
                        'appointment_id' => $result->appointment->getKey(),
                        'dealership_id' => $result->appointment->dealership_id,
                        'exception_class' => $exception::class,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }

            return $result;
        } catch (QueryException $exception) {
            // Waited too long for the dealership row lock (or deadlock): fail fast, client may retry.
            if ($this->isLockWaitFailure($exception)) {
                throw new BookingInProgressException(previous: $exception);
            }

            throw $exception;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable $exception) {
                    // The DB transaction has already established the authoritative result.
                    Log::warning('booking.redis_lock_release_failed', [
                        'dealership_id' => $data->dealershipId,
                        'exception_class' => $exception::class,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * PostgreSQL only: bound the wait for the dealership row lock for THIS transaction.
     * SET LOCAL resets automatically when the transaction ends.
     */
    private function limitLockWait(): void
    {
        $milliseconds = (int) config('booking.db_lock_timeout_ms', 5000);

        if ($milliseconds > 0 && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '{$milliseconds}ms'");
        }
    }

    private function isLockWaitFailure(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return in_array((string) $sqlState, self::LOCK_WAIT_SQLSTATES, true);
    }
}
