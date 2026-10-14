<?php

namespace App\DTOs\Appointment;

use Carbon\CarbonImmutable;

final readonly class AppointmentPeriodData
{
    public function __construct(
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
    ) {}
}
