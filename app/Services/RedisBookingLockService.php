<?php

namespace App\Services;

use App\Exceptions\BookingInProgressException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RedisBookingLockService
{
    public function acquire(string $dealershipId): ?Lock
    {
        try {
            $lock = Cache::store('redis')->lock("booking:dealership:{$dealershipId}", 10);

            if (! $lock->get()) {
                throw new BookingInProgressException();
            }

            return $lock;
        } catch (BookingInProgressException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Redis is a supporting first layer. DB locking remains the authoritative fallback.
            return null;
        }
    }
}
