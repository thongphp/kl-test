<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class BusinessValidationException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => [
                $this->field => [$this->getMessage()],
            ],
        ], 422);
    }
}
