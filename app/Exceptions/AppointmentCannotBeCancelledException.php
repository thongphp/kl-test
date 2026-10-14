<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class AppointmentCannotBeCancelledException extends RuntimeException
{
    public function __construct(string $message = 'The appointment cannot be cancelled.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'APPOINTMENT_CANNOT_BE_CANCELLED',
        ], 409);
    }
}
