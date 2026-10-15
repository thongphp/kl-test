<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class BookingInProgressException extends RuntimeException
{
    public function __construct(
        string $message = 'Another booking is currently being processed for this dealership.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'BOOKING_IN_PROGRESS',
        ], 409);
    }
}
