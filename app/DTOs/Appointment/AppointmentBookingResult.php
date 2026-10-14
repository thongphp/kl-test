<?php

namespace App\DTOs\Appointment;

use App\Models\Appointment;

final readonly class AppointmentBookingResult
{
    public function __construct(
        public Appointment $appointment,
        public bool $replayed,
    ) {}

    public function responseStatus(): int
    {
        return $this->replayed ? 200 : 201;
    }
}
