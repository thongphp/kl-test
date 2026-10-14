<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class IdempotencyKeyMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key has already been used with a different request.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'IDEMPOTENCY_KEY_MISMATCH',
        ], 422);
    }
}
