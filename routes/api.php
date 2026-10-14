<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
    ]));

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->name('api.v1.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store'])
            ->name('api.v1.appointments.store');
        Route::get('/appointments', [AppointmentController::class, 'index'])
            ->name('api.v1.appointments.index');
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])
            ->name('api.v1.appointments.show');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->name('api.v1.appointments.cancel');
    });
});
