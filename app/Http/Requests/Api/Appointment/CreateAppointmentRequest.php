<?php

namespace App\Http\Requests\Api\Appointment;

use Illuminate\Foundation\Http\FormRequest;

class CreateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'uuid'],
            'dealership_id' => ['required', 'uuid'],
            'service_type_id' => ['required', 'uuid'],
            'start_at' => [
                'required',
                'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                'after:now',
            ],
            'customer_id' => ['prohibited'],
            'technician_id' => ['prohibited'],
            'service_bay_id' => ['prohibited'],
            'end_at' => ['prohibited'],
        ];
    }
}
