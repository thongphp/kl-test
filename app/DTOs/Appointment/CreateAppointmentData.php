<?php

namespace App\DTOs\Appointment;

use Carbon\CarbonImmutable;

final readonly class CreateAppointmentData
{
    public function __construct(
        public string $customerId,
        public string $vehicleId,
        public string $dealershipId,
        public string $serviceTypeId,
        public CarbonImmutable $startAt,
    ) {}
}
