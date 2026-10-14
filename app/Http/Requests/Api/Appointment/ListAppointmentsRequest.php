<?php

namespace App\Http\Requests\Api\Appointment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['confirmed', 'cancelled'])],
            'vehicle_id' => ['sometimes', 'uuid'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
