<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\ServiceBay;
use Carbon\CarbonImmutable;

final class ServiceBayService
{
    public function findAvailable(Dealership $dealership, CarbonImmutable $startAt, CarbonImmutable $endAt): ?ServiceBay
    {
        return $dealership->serviceBays()
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
