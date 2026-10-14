<?php

namespace App\Services;

use App\DTOs\Appointment\CreateAppointmentData;
use App\Exceptions\IdempotencyKeyMismatchException;
use App\Models\Appointment;
use App\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdempotencyService
{
    private const RETENTION_HOURS = 24;

    public function fingerprint(CreateAppointmentData $data): string
    {
        return hash('sha256', json_encode([
            'vehicle_id' => $data->vehicleId,
            'dealership_id' => $data->dealershipId,
            'service_type_id' => $data->serviceTypeId,
            'start_at' => $data->startAt->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Returns an existing successful result or creates the idempotency record
     * that will be completed by the surrounding booking transaction.
     *
     * @return array{record: IdempotencyKey, replay: bool}
     */
    public function begin(string $customerId, string $key, string $fingerprint): array
    {
        $now = CarbonImmutable::now();

        $existing = IdempotencyKey::query()
            ->where('customer_id', $customerId)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->expires_at->lte($now)) {
                $existing->delete();
            } else {
                $this->assertFingerprintMatches($existing, $fingerprint);

                if ($existing->appointment_id !== null) {
                    return ['record' => $existing, 'replay' => true];
                }
            }
        }

        $id = (string) Str::uuid();

        DB::table('idempotency_keys')->insertOrIgnore([
            'id' => $id,
            'customer_id' => $customerId,
            'key' => $key,
            'request_fingerprint' => $fingerprint,
            'appointment_id' => null,
            'response_status' => null,
            'expires_at' => $now->addHours(self::RETENTION_HOURS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = IdempotencyKey::query()
            ->where('customer_id', $customerId)
            ->where('key', $key)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertFingerprintMatches($record, $fingerprint);

        if ($record->appointment_id !== null) {
            return ['record' => $record, 'replay' => true];
        }

        return ['record' => $record, 'replay' => false];
    }

    public function complete(IdempotencyKey $record, Appointment $appointment): void
    {
        $record->forceFill([
            'appointment_id' => $appointment->getKey(),
            'response_status' => 201,
        ])->save();
    }

    public function appointment(IdempotencyKey $record): Appointment
    {
        return $record->appointment()->firstOrFail();
    }

    private function assertFingerprintMatches(IdempotencyKey $record, string $fingerprint): void
    {
        if (! hash_equals($record->request_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyMismatchException();
        }
    }
}
