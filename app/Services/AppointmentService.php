<?php

namespace App\Services;

use App\DTOs\Appointment\CreateAppointmentData;
use App\Exceptions\AppointmentCannotBeCancelledException;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AppointmentService
{
    public function __construct(
        private readonly RedisAvailabilityService $redisAvailabilityService,
        private readonly RedisBookingLockService $redisBookingLockService,
    ) {}

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

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Appointment>
     */
    public function paginateForCustomer(Customer $customer, ?string $status, ?string $vehicleId, int $perPage)
    {
        return $customer->appointments()
            ->with(['vehicle', 'dealership', 'serviceType', 'technician', 'serviceBay'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($vehicleId !== null, fn ($query) => $query->where('vehicle_id', $vehicleId))
            ->orderByDesc('start_at')
            ->paginate($perPage);
    }

    public function cancel(Appointment $appointment): Appointment
    {
        $lock = $this->redisBookingLockService->acquire($appointment->dealership_id);

        try {
            $cancelled = DB::transaction(function () use ($appointment): Appointment {
                // Lock the dealership first so cancellation serializes with the existing
                // booking transaction for the same dealership.
                Dealership::query()
                    ->whereKey($appointment->dealership_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAppointment = Appointment::query()
                    ->whereKey($appointment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedAppointment->status !== 'confirmed'
                    || $lockedAppointment->start_at === null
                    || ! $lockedAppointment->start_at->isFuture()
                ) {
                    throw new AppointmentCannotBeCancelledException();
                }

                $lockedAppointment->update(['status' => 'cancelled']);

                return $lockedAppointment->refresh();
            });

            try {
                $this->redisAvailabilityService->removeAppointmentIndex(
                    appointmentId: $cancelled->getKey(),
                    technicianId: $cancelled->technician_id,
                    serviceBayId: $cancelled->service_bay_id,
                );
            } catch (Throwable) {
                // The database is authoritative. A failed advisory-index update must not
                // invalidate a committed cancellation.
            }

            return $cancelled;
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The database has already established the authoritative cancellation.
                }
            }
        }
    }
}
