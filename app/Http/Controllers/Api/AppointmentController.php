<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointment\CreateAppointmentAction;
use App\DTOs\Appointment\CreateAppointmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointment\CreateAppointmentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class AppointmentController extends Controller
{
    public function store(CreateAppointmentRequest $request, CreateAppointmentAction $action): JsonResponse
    {
        $customer = $request->user();
        $validated = $request->validated();

        $appointment = $action->execute(new CreateAppointmentData(
            customerId: $customer->getKey(),
            vehicleId: $validated['vehicle_id'],
            dealershipId: $validated['dealership_id'],
            serviceTypeId: $validated['service_type_id'],
            startAt: CarbonImmutable::parse($validated['start_at'])->utc(),
        ));

        return response()->json([
            'data' => $appointment,
        ], 201);
    }
}
