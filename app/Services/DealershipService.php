<?php

namespace App\Services;

use App\Exceptions\BusinessValidationException;
use App\Models\Dealership;

final class DealershipService
{
    public function findActive(string $dealershipId): Dealership
    {
        $dealership = Dealership::withTrashed()->find($dealershipId);

        if ($dealership === null) {
            throw new BusinessValidationException(
                'DEALERSHIP_NOT_FOUND',
                'dealership_id',
                'The selected dealership does not exist.',
            );
        }

        if ($dealership->trashed() || ! $dealership->active) {
            throw new BusinessValidationException(
                'DEALERSHIP_INACTIVE',
                'dealership_id',
                'The selected dealership is unavailable.',
            );
        }

        return $dealership;
    }

    public function lockActive(string $dealershipId): Dealership
    {
        $dealership = Dealership::withTrashed()
            ->whereKey($dealershipId)
            ->lockForUpdate()
            ->first();

        if ($dealership === null) {
            throw new BusinessValidationException(
                'DEALERSHIP_NOT_FOUND',
                'dealership_id',
                'The selected dealership does not exist.',
            );
        }

        if ($dealership->trashed() || ! $dealership->active) {
            throw new BusinessValidationException(
                'DEALERSHIP_INACTIVE',
                'dealership_id',
                'The selected dealership is unavailable.',
            );
        }

        return $dealership;
    }
}
