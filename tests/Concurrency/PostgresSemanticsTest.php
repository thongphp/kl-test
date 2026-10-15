<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\IdempotencyKey;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| UUID
|--------------------------------------------------------------------------
*/

it('uses native uuid columns for primary and foreign keys', function () {
    $columnType = fn (string $table, string $column) => DB::selectOne(
        'select data_type from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
        [$table, $column],
    )?->data_type;

    $primaryKeys = collect([
        Customer::class, Vehicle::class, Dealership::class, Technician::class,
        ServiceBay::class, ServiceType::class, Appointment::class, IdempotencyKey::class,
    ])->mapWithKeys(fn (string $model) => [(new $model)->getTable() => $columnType((new $model)->getTable(), 'id')]);

    $foreignKeys = collect(['customer_id', 'vehicle_id', 'dealership_id', 'service_type_id', 'technician_id', 'service_bay_id'])
        ->mapWithKeys(fn (string $column) => ["appointments.{$column}" => $columnType((new Appointment)->getTable(), $column)]);

    expect($primaryKeys->all())->each->toBe('uuid')
        ->and($foreignKeys->all())->each->toBe('uuid');
});

/*
|--------------------------------------------------------------------------
| Indexes
|--------------------------------------------------------------------------
*/

it('has the composite availability indexes', function (string $column) {
    $definitions = collect(DB::select(
        'select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?',
        [(new Appointment)->getTable()],
    ))->pluck('indexdef');

    expect($definitions->contains(fn (string $def) => str_contains($def, "({$column}, start_at, end_at)")))->toBeTrue();
})->with(['technician_id', 'service_bay_id']);

it('has the unique customer and key index for idempotency', function () {
    $definitions = collect(DB::select(
        'select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?',
        [(new IdempotencyKey)->getTable()],
    ))->pluck('indexdef');

    expect($definitions->contains(
        fn (string $def) => preg_match('/CREATE UNIQUE INDEX .+\(customer_id, "?key"?\)/', $def) === 1,
    ))->toBeTrue();
});

it('indexes foreign key lookups with the foreign key as leading column', function (string $model, string $column) {
    $definitions = collect(DB::select(
        'select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?',
        [(new $model)->getTable()],
    ))->pluck('indexdef');

    expect($definitions->contains(
        fn (string $def) => preg_match('/\("?'.$column.'"?[,)]/', $def) === 1,
    ))->toBeTrue();
})->with([
    'appointments.customer_id' => [Appointment::class, 'customer_id'],
    'appointments.vehicle_id' => [Appointment::class, 'vehicle_id'],
    'appointments.dealership_id' => [Appointment::class, 'dealership_id'],
    'appointments.service_type_id' => [Appointment::class, 'service_type_id'],
    'appointments.technician_id' => [Appointment::class, 'technician_id'],
    'appointments.service_bay_id' => [Appointment::class, 'service_bay_id'],
    'technicians.dealership_id' => [Technician::class, 'dealership_id'],
    'service_bays.dealership_id' => [ServiceBay::class, 'dealership_id'],
    'vehicles.customer_id' => [Vehicle::class, 'customer_id'],
]);

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/

it('runs the application in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

it('keeps appointment instants identical across database session timezones', function (object $booking, string $writeTz, string $readTz) {
    DB::statement("SET TIME ZONE '{$writeTz}'");

    $id = $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertCreated()
        ->json('data.id');

    DB::statement("SET TIME ZONE '{$readTz}'");

    $appointment = Appointment::query()->findOrFail($id);

    expect($appointment->start_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-01 03:00:00')
        ->and($appointment->end_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-01 04:00:00');
})->with('booking')->with([
    'write UTC, read Bangkok' => ['UTC', 'Asia/Bangkok'],
    'write Bangkok, read UTC' => ['Asia/Bangkok', 'UTC'],
    'write New York, read Bangkok' => ['America/New_York', 'Asia/Bangkok'],
]);

/*
|--------------------------------------------------------------------------
| Soft deletes
|--------------------------------------------------------------------------
*/

it('hides soft deleted resources from normal queries and from booking', function (object $booking) {
    $booking->technician->delete();

    $table = (new Technician)->getTable();

    expect(Technician::query()->whereKey($booking->technician->id)->exists())->toBeFalse()
        ->and(Technician::withTrashed()->whereKey($booking->technician->id)->exists())->toBeTrue()
        ->and(DB::table($table)->where('id', $booking->technician->id)->whereNotNull('deleted_at')->exists())->toBeTrue();

    $this->postJson('/api/v1/appointments', bookingPayload($booking))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_RESOURCE_AVAILABLE');
})->with('booking');

/*
|--------------------------------------------------------------------------
| Locking
|--------------------------------------------------------------------------
|
| A second connection holds FOR UPDATE on the dealership row. Booking must NOT
| get through while that lock is held: it waits up to booking.db_lock_timeout_ms,
| then fails fast with 409 BOOKING_IN_PROGRESS. After the lock is released the
| same booking works. The retry also proves the Redis dealership lock is released
| after the failed attempt.
|
*/

it('takes a row lock on the dealership and fails fast with BOOKING_IN_PROGRESS on lock timeout', function (object $booking) {
    config()->set('booking.db_lock_timeout_ms', 1000);
    config()->set('database.connections.lock_holder', config('database.connections.'.config('database.default')));

    $holder = DB::connection('lock_holder');
    $holder->beginTransaction();

    try {
        $holder->table((new Dealership)->getTable())
            ->where('id', $booking->dealership->id)
            ->lockForUpdate()
            ->first();

        $startedAt = microtime(true);

        $this->postJson('/api/v1/appointments', bookingPayload($booking))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BOOKING_IN_PROGRESS');

        // Failed fast (about the 1s timeout), did not hang.
        expect(microtime(true) - $startedAt)->toBeLessThan(5.0)
            ->and(Appointment::query()->count())->toBe(0);
    } finally {
        $holder->rollBack();
        DB::purge('lock_holder');
    }

    // Lock released: booking works again (also proves the Redis lock was released).
    $this->postJson('/api/v1/appointments', bookingPayload($booking))->assertCreated();
})->with('booking');
