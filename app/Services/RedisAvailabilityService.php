<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Dealership;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis is an advisory first-layer availability index.
 * The database remains authoritative and always performs the final overlap check.
 */
final class RedisAvailabilityService
{
    public function candidateTechnicianIds(
        Dealership $dealership,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): ?array {
        return $this->candidateIds(
            resourceKey: "booking:availability:dealership:{$dealership->getKey()}:technicians",
            resourcePrefix: 'booking:availability:technician:',
            resources: $dealership->technicians()->where('active', true)->get(),
            startAt: $startAt,
            endAt: $endAt,
        );
    }

    public function candidateServiceBayIds(
        Dealership $dealership,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): ?array {
        return $this->candidateIds(
            resourceKey: "booking:availability:dealership:{$dealership->getKey()}:service-bays",
            resourcePrefix: 'booking:availability:service-bay:',
            resources: $dealership->serviceBays()->where('active', true)->get(),
            startAt: $startAt,
            endAt: $endAt,
        );
    }

    public function indexAppointment(
        string $appointmentId,
        string $technicianId,
        string $serviceBayId,
        string $dealershipId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
    ): void {
        try {
            $redis = Redis::connection();
            $startTimestamp = $startAt->timestamp;
            $endTimestamp = $endAt->timestamp;

            $redis->sadd("booking:availability:dealership:{$dealershipId}:technicians", $technicianId);
            $redis->sadd("booking:availability:dealership:{$dealershipId}:service-bays", $serviceBayId);
            $redis->hset("booking:availability:appointment:{$appointmentId}", 'end_at', (string) $endTimestamp);
            $redis->zadd("booking:availability:technician:{$technicianId}", $startTimestamp, $appointmentId);
            $redis->zadd("booking:availability:service-bay:{$serviceBayId}", $startTimestamp, $appointmentId);
        } catch (Throwable) {
            // Redis is advisory. A failed index update must never invalidate a committed DB booking.
        }
    }

    private function candidateIds(
        string $resourceKey,
        string $resourcePrefix,
        $resources,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
    ): ?array {
        try {
            $redis = Redis::connection();
            $resourceIds = $redis->smembers($resourceKey);
            $activeResourceIds = $resources->modelKeys();

            if ($resourceIds === []) {
                $resourceIds = $activeResourceIds;
                if ($resourceIds !== []) {
                    $redis->sadd($resourceKey, ...$resourceIds);
                }

                $this->warmExistingAppointments($resources, $resourcePrefix);
            } else {
                // Keep the Redis resource index aligned with current active resources.
                foreach ($activeResourceIds as $resourceId) {
                    $redis->sadd($resourceKey, $resourceId);
                }
                $resourceIds = $activeResourceIds;
            }

            $candidates = [];
            foreach ($resourceIds as $resourceId) {
                if (! in_array($resourceId, $activeResourceIds, true)) {
                    continue;
                }

                $appointmentIds = $redis->zrangebyscore(
                    "{$resourcePrefix}{$resourceId}",
                    '-inf',
                    $endAt->timestamp,
                );

                $busy = false;
                foreach ($appointmentIds as $appointmentId) {
                    $endTimestamp = $redis->hget("booking:availability:appointment:{$appointmentId}", 'end_at');
                    if ($endTimestamp !== null && (int) $endTimestamp > $startAt->timestamp) {
                        $busy = true;
                        break;
                    }
                }

                if (! $busy) {
                    $candidates[] = (string) $resourceId;
                }
            }

            sort($candidates, SORT_STRING);

            return $candidates;
        } catch (Throwable) {
            // Redis unavailable: return null so the DB-only authoritative path continues.
            return null;
        }
    }

    private function warmExistingAppointments($resources, string $resourcePrefix): void
    {
        $resourceIds = $resources->modelKeys();
        if ($resourceIds === []) {
            return;
        }

        $column = str_contains($resourcePrefix, 'technician:') ? 'technician_id' : 'service_bay_id';

        Appointment::query()
            ->whereIn($column, $resourceIds)
            ->where('status', 'confirmed')
            ->get(['id', $column, 'start_at', 'end_at'])
            ->each(function (Appointment $appointment) use ($resourcePrefix, $column) {
                $resourceId = $appointment->getAttribute($column);
                $redis = Redis::connection();
                $redis->hset(
                    "booking:availability:appointment:{$appointment->getKey()}",
                    'end_at',
                    (string) $appointment->end_at->timestamp,
                );
                $redis->zadd(
                    "{$resourcePrefix}{$resourceId}",
                    $appointment->start_at->timestamp,
                    $appointment->getKey(),
                );
            });
    }
}
