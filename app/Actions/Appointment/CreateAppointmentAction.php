<?php

namespace App\Actions\Appointment;

use App\DTOs\Appointment\CreateAppointmentData;
use App\Models\Appointment;
use App\Services\AppointmentBookingService;

final class CreateAppointmentAction
{
    public function __construct(private readonly AppointmentBookingService $bookingService) {}

    public function execute(CreateAppointmentData $data): Appointment
    {
        return $this->bookingService->book($data);
    }
}
