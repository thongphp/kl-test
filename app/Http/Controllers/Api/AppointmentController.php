<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointment\CancelAppointmentAction;
use App\Actions\Appointment\CreateAppointmentAction;
use App\DTOs\Appointment\CreateAppointmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointment\CreateAppointmentRequest;
use App\Http\Requests\Api\Appointment\ListAppointmentsRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AppointmentController extends Controller
{
    public function store(CreateAppointmentRequest $request, CreateAppointmentAction $action): JsonResponse
    {
        $customer = $request->user();
        $validated = $request->validated();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        $result = $action->execute(
            new CreateAppointmentData(
                customerId: $customer->getKey(),
                vehicleId: $validated['vehicle_id'],
                dealershipId: $validated['dealership_id'],
                serviceTypeId: $validated['service_type_id'],
                startAt: CarbonImmutable::parse($validated['start_at'])->utc(),
            ),
            $idempotencyKey,
        );

        return (new AppointmentResource($result->appointment))
            ->response()
            ->setStatusCode($result->responseStatus());
    }

    public function index(ListAppointmentsRequest $request, AppointmentService $appointmentService): AnonymousResourceCollection
    {
        $customer = $request->user();
        $validated = $request->validated();

        return AppointmentResource::collection(
            $appointmentService->paginateForCustomer(
                customer: $customer,
                status: $validated['status'] ?? null,
                vehicleId: $validated['vehicle_id'] ?? null,
                perPage: $request->perPage(),
            ),
        );
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource(
            $appointment->load(['vehicle', 'dealership', 'serviceType', 'technician', 'serviceBay']),
        );
    }

    public function cancel(Appointment $appointment, CancelAppointmentAction $action): AppointmentResource
    {
        Gate::authorize('cancel', $appointment);

        return new AppointmentResource($action->execute($appointment));
    }
}
