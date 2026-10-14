<?php

namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Services\AppointmentService;

final class CancelAppointmentAction
{
    public function __construct(private readonly AppointmentService $appointmentService) {}

    public function execute(Appointment $appointment): Appointment
    {
        return $this->appointmentService->cancel($appointment);
    }
}
