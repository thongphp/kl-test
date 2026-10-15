<?php

namespace App\Exceptions;

use App\Models\Appointment;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Structured booking logs (Phase 9 observability).
 *
 * Domain exceptions are expected outcomes, so they are logged as one structured line
 * (no stack trace) and then reporting stops (`return false`) instead of Laravel's default
 * error-level dump. Anything else that fails on the appointment endpoints is logged as
 * `booking.failed` with the exception.
 *
 * Context carries identifiers only. Never names, emails, plates or the Idempotency-Key value.
 */
final class BookingExceptionReporter
{
    public static function register(Exceptions $exceptions): void
    {
        // Expected 422 business rejections: not an error, nothing to report.
        $exceptions->dontReport([BusinessValidationException::class]);

        $exceptions->report(fn (BookingInProgressException $e) => self::write('warning', 'booking.lock_contention', $e));
        $exceptions->report(fn (ResourceConflictException $e) => self::write('info', 'booking.resource_conflict', $e));
        $exceptions->report(fn (IdempotencyKeyMismatchException $e) => self::write('warning', 'booking.idempotency_mismatch', $e));
        $exceptions->report(fn (AppointmentCannotBeCancelledException $e) => self::write('info', 'booking.cancellation_conflict', $e));

        // Unexpected failures on the appointment endpoints (registered last: specific callbacks win).
        $exceptions->report(function (Throwable $e) {
            if (! request()->is('api/v1/appointments*')) {
                return null; // not ours: keep default reporting
            }

            return self::write('error', 'booking.failed', $e, ['exception' => $e], withReason: false);
        });
    }

    private static function write(string $level, string $event, Throwable $e, array $extra = [], bool $withReason = true): bool
    {
        $context = self::context($e, $withReason) + $extra;

        $channel = config('booking.log_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->{$level}($event, $context);

        return false; // stop default reporting: this event is already logged
    }

    private static function context(Throwable $e, bool $withReason): array
    {
        try {
            $request = request();
            $appointment = $request->route('appointment');

            $context = [
                'customer_id' => $request->user()?->getKey(),
                'dealership_id' => self::scalar($request->input('dealership_id')),
                'service_type_id' => self::scalar($request->input('service_type_id')),
                'start_at' => self::scalar($request->input('start_at')),
                'appointment_id' => $appointment instanceof Appointment ? $appointment->getKey() : self::scalar($appointment),
                'idempotent' => $request->hasHeader('Idempotency-Key'),
                'exception_class' => $e::class,
            ];

            if ($withReason) {
                $context['reason'] = $e->getMessage();
            }

            return array_filter($context, fn ($value) => $value !== null);
        } catch (Throwable) {
            // Building context must never hide the original failure.
            return ['exception_class' => $e::class];
        }
    }

    private static function scalar(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
