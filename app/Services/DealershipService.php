<?php

namespace App\Services;

use App\Models\Dealership;
use Illuminate\Validation\ValidationException;

final class DealershipService
{
    public function findActive(string $dealershipId): Dealership
    {
        $dealership = Dealership::withTrashed()->find($dealershipId);

        if ($dealership === null) {
            throw ValidationException::withMessages(['dealership_id' => 'The selected dealership does not exist.']);
        }

        if ($dealership->trashed() || ! $dealership->active) {
            throw ValidationException::withMessages(['dealership_id' => 'The selected dealership is unavailable.']);
        }

        return $dealership;
    }
}
