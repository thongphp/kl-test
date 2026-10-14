<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class BookingInProgressException extends RuntimeException
{
    public function __construct(string $message = 'Another booking is currently being processed for this dealership.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'BOOKING_IN_PROGRESS',
        ], 409);
    }
}
