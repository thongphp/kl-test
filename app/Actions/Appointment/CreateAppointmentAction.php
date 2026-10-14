<?php

namespace App\Actions\Appointment;

use App\DTOs\Appointment\AppointmentBookingResult;
use App\DTOs\Appointment\CreateAppointmentData;
use App\Services\AppointmentBookingService;

final class CreateAppointmentAction
{
    public function __construct(private readonly AppointmentBookingService $bookingService) {}

    public function execute(CreateAppointmentData $data, ?string $idempotencyKey = null): AppointmentBookingResult
    {
        return $this->bookingService->book($data, $idempotencyKey);
    }
}
