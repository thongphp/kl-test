<?php

namespace App\Http\Resources;

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
            'start_at' => $this->start_at?->toISOString(),
            'end_at' => $this->end_at?->toISOString(),
            'vehicle' => $this->summary($this->vehicle),
            'dealership' => $this->summary($this->dealership),
            'service_type' => $this->summary($this->serviceType),
            'technician' => $this->summary($this->technician),
            'service_bay' => $this->summary($this->serviceBay),
        ];
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
