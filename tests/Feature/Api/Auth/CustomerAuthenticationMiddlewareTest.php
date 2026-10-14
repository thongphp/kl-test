<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::get('/api/v1/test/customer-protected', function () {
        return response()->json([
            'customer_id' => request()->user()->getKey(),
        ]);
    })->middleware('auth:sanctum');
});

it('rejects an unauthenticated customer API request', function () {
    $this->getJson('/api/v1/test/customer-protected')
        ->assertUnauthorized();
});

it('resolves the authenticated customer from the Sanctum user context', function () {
    $customer = Customer::factory()->create();

    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/test/customer-protected')
        ->assertOk()
        ->assertJsonPath('customer_id', $customer->id);
});
