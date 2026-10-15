<?php

return [
    /*
    | Max time a booking waits for the dealership row lock (PostgreSQL SET LOCAL lock_timeout).
    | On timeout the request fails fast with 409 BOOKING_IN_PROGRESS instead of hanging.
    | 0 disables the limit.
    */
    'db_lock_timeout_ms' => (int) env('BOOKING_DB_LOCK_TIMEOUT_MS', 5000),

    /*
    | Log channel for booking events (booking.*). null = default channel.
    */
    'log_channel' => env('BOOKING_LOG_CHANNEL'),
];
