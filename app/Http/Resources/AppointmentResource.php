<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Appointment */
final class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'start_at' => $this->inDealershipTimezone($this->start_at),
            'end_at' => $this->inDealershipTimezone($this->end_at),
            'vehicle' => $this->summary($this->vehicle),
            'dealership' => $this->summary($this->dealership),
            'service_type' => $this->summary($this->serviceType),
            'technician' => $this->summary($this->technician),
            'service_bay' => $this->summary($this->serviceBay),
        ];
    }

    /**
     * Storage is UTC; clients see the instant with the dealership's UTC offset,
     * e.g. 2030-11-01T10:00:00+07:00.
     */
    private function inDealershipTimezone(?CarbonInterface $time): ?string
    {
        return $time?->copy()->setTimezone($this->dealershipTimezone())->toIso8601String();
    }

    private function dealershipTimezone(): string
    {
        $timezone = $this->dealership?->timezone;

        if (! is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return 'UTC';
        }

        return $timezone;
    }

    /**
     * @param object|null $model
     * @return array<string, mixed>|null
     */
    private function summary(?object $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'id' => $model->getKey(),
            'name' => $model->name,
        ];
    }
}
