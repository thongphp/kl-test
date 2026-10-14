<?php

namespace App\Services;

use App\Models\ServiceType;
use Illuminate\Validation\ValidationException;

final class ServiceTypeService
{
    public function findActive(string $serviceTypeId): ServiceType
    {
        $serviceType = ServiceType::withTrashed()->find($serviceTypeId);

        if ($serviceType === null) {
            throw ValidationException::withMessages(['service_type_id' => 'The selected service type does not exist.']);
        }

        if ($serviceType->trashed() || ! $serviceType->active) {
            throw ValidationException::withMessages(['service_type_id' => 'The selected service type is unavailable.']);
        }

        if ($serviceType->duration <= 0) {
            throw ValidationException::withMessages(['service_type_id' => 'The selected service type has an invalid duration.']);
        }

        return $serviceType;
    }
}
