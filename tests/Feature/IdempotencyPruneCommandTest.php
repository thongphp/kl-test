<?php

use App\Models\IdempotencyKey;

function idempotencyKeyFor(object $booking, string $key, $expiresAt): IdempotencyKey
{
    return IdempotencyKey::factory()->create([
        'customer_id' => $booking->customer->id,
        'key' => $key,
        'request_fingerprint' => 'fingerprint-'.$key,
        'expires_at' => $expiresAt,
    ]);
}

it('prunes expired keys and never touches active ones', function (object $booking) {
    $expired = idempotencyKeyFor($booking, 'expired', now()->subMinute());
    $active = idempotencyKeyFor($booking, 'active', now()->addHour());

    $this->artisan('idempotency:prune')
        ->expectsOutputToContain('expired=1')
        ->assertSuccessful();

    expect(IdempotencyKey::query()->whereKey($expired->getKey())->exists())->toBeFalse()
        ->and(IdempotencyKey::query()->whereKey($active->getKey())->exists())->toBeTrue();
})->with('booking');

it('deletes nothing on a dry run', function (object $booking) {
    idempotencyKeyFor($booking, 'expired', now()->subMinute());
    idempotencyKeyFor($booking, 'active', now()->addHour());

    $this->artisan('idempotency:prune --dry-run')->assertSuccessful();

    expect(IdempotencyKey::query()->count())->toBe(2);
})->with('booking');
