<?php

namespace App\Services;

use App\Exceptions\BusinessValidationException;
use App\Models\ServiceType;

final class ServiceTypeService
{
    public function findActive(string $serviceTypeId): ServiceType
    {
        $serviceType = ServiceType::withTrashed()->find($serviceTypeId);

        if ($serviceType === null) {
            throw new BusinessValidationException(
                'SERVICE_TYPE_NOT_FOUND',
                'service_type_id',
                'The selected service type does not exist.',
            );
        }

        if ($serviceType->trashed() || ! $serviceType->active) {
            throw new BusinessValidationException(
                'SERVICE_TYPE_INACTIVE',
                'service_type_id',
                'The selected service type is unavailable.',
            );
        }

        if ($serviceType->duration <= 0) {
            throw new BusinessValidationException(
                'SERVICE_TYPE_INVALID_DURATION',
                'service_type_id',
                'The selected service type has an invalid duration.',
            );
        }

        return $serviceType;
    }
}
