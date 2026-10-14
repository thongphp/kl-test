<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\Technician;
use Carbon\CarbonImmutable;

final class TechnicianService
{
    public function findAvailable(Dealership $dealership, CarbonImmutable $startAt, CarbonImmutable $endAt): ?Technician
    {
        return $dealership->technicians()
            ->where('active', true)
            ->whereDoesntHave('appointments', function ($query) use ($startAt, $endAt) {
                $query
                    ->where('status', '!=', 'cancelled')
                    ->where('start_at', '<', $endAt)
                    ->where('end_at', '>', $startAt);
            })
            ->orderBy('id')
            ->first();
    }
}
