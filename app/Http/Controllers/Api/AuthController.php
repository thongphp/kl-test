<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\LoginCustomerAction;
use App\DTOs\Auth\LoginCustomerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginCustomerRequest;
use Illuminate\Http\JsonResponse;

final class AuthController extends Controller
{
    public function login(
        LoginCustomerRequest $request,
        LoginCustomerAction $action,
    ): JsonResponse {
        $data = new LoginCustomerData(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
        );

        $authenticated = $action->execute($data);

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $authenticated->customer->id,
                    'first_name' => $authenticated->customer->first_name,
                    'last_name' => $authenticated->customer->last_name,
                    'email' => $authenticated->customer->email,
                ],
                'token' => $authenticated->token,
            ],
        ]);
    }
}
