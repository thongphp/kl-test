<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\Technician;
use Carbon\CarbonImmutable;

final class TechnicianService
{
    public function findAvailable(
        Dealership $dealership,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        ?array $preferredIds = null,
    ): ?Technician {
        $technicians = $dealership->technicians()
            ->where('active', true)
            ->whereDoesntHave('appointments', function ($query) use ($startAt, $endAt) {
                $query
                    ->where('status', '!=', 'cancelled')
                    ->where('start_at', '<', $endAt)
                    ->where('end_at', '>', $startAt);
            })
            ->get();

        if ($technicians->isEmpty()) {
            return null;
        }

        if ($preferredIds !== null) {
            $rank = array_flip($preferredIds);
            return $technicians->sort(function (Technician $a, Technician $b) use ($rank) {
                $rankA = $rank[$a->getKey()] ?? PHP_INT_MAX;
                $rankB = $rank[$b->getKey()] ?? PHP_INT_MAX;

                return $rankA === $rankB
                    ? strcmp((string) $a->getKey(), (string) $b->getKey())
                    : $rankA <=> $rankB;
            })->first();
        }

        return $technicians->sortBy('id')->first();
    }
}
