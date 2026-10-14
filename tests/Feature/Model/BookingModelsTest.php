<?php

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Domain / model tests
|--------------------------------------------------------------------------
*/

it('uses UUID primary keys', function (Model $model) {
    expect(Str::isUuid((string) $model->getKey()))->toBeTrue();
})->with('core models');

it('persists valid data from its factory', function (Model $model) {
    expect($model->wasRecentlyCreated)->toBeTrue()
        ->and($model::query()->whereKey($model->getKey())->exists())->toBeTrue();
})->with('core models');

it('is soft deletable and hidden from normal queries once deleted', function (Model $model) {
    expect(class_uses_recursive($model))->toContain(SoftDeletes::class);

    $model->delete();

    expect($model::query()->find($model->getKey()))->toBeNull()
        ->and($model::withTrashed()->find($model->getKey()))->not->toBeNull()
        ->and($model::withTrashed()->find($model->getKey())->trashed())->toBeTrue();
})->with('core models');

it('casts the active flag to a boolean', function (Model $model) {
    $model->update(['active' => false]);
    expect($model->fresh()->active)->toBeFalse();

    $model->update(['active' => true]);
    expect($model->fresh()->active)->toBeTrue();
})->with([
    'dealership' => [fn () => Dealership::factory()->create()],
    'technician' => [fn () => Technician::factory()->for(Dealership::factory())->create()],
    'service bay' => [fn () => ServiceBay::factory()->for(Dealership::factory())->create()],
    'service type' => [fn () => ServiceType::factory()->create()],
]);

it('persists appointment statuses', function (string $status) {
    $appointment = Appointment::factory()->create(['status' => $status]);

    expect($appointment->fresh()->status)->toBe($status);
})->with(['confirmed', 'cancelled']);

it('maintains the belongsTo relationships', function (object $booking) {
    $appointment = existingAppointment($booking);

    expect($booking->vehicle->customer()->is($booking->customer))->toBeTrue()
        ->and($booking->technician->dealership()->is($booking->dealership))->toBeTrue()
        ->and($booking->serviceBay->dealership()->is($booking->dealership))->toBeTrue()
        ->and($appointment->customer()->is($booking->customer))->toBeTrue()
        ->and($appointment->vehicle()->is($booking->vehicle))->toBeTrue()
        ->and($appointment->dealership()->is($booking->dealership))->toBeTrue()
        ->and($appointment->serviceType()->is($booking->serviceType))->toBeTrue()
        ->and($appointment->technician()->is($booking->technician))->toBeTrue()
        ->and($appointment->serviceBay()->is($booking->serviceBay))->toBeTrue();
})->with('booking');

it('maintains the hasMany relationships', function (object $booking) {
    $appointment = existingAppointment($booking);

    $ids = fn ($relation) => $relation->pluck('id')->all();

    expect($ids($booking->customer->vehicles))->toContain($booking->vehicle->id)
        ->and($ids($booking->customer->appointments))->toContain($appointment->id)
        ->and($ids($booking->vehicle->appointments))->toContain($appointment->id)
        ->and($ids($booking->dealership->technicians))->toContain($booking->technician->id)
        ->and($ids($booking->dealership->serviceBays))->toContain($booking->serviceBay->id)
        ->and($ids($booking->dealership->appointments))->toContain($appointment->id)
        ->and($ids($booking->serviceType->appointments))->toContain($appointment->id)
        ->and($ids($booking->technician->appointments))->toContain($appointment->id)
        ->and($ids($booking->serviceBay->appointments))->toContain($appointment->id);
})->with('booking');
