<?php

use Illuminate\Support\Facades\Schema;

it('has the phase 1 domain tables', function () {
    foreach ([
        'customers',
        'vehicles',
        'dealerships',
        'technicians',
        'service_bays',
        'service_types',
        'appointments',
        'idempotency_keys',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('has the required appointment overlap indexes', function () {
    $indexes = Schema::getIndexes('appointments');

    $indexColumns = collect($indexes)->mapWithKeys(
        fn (array $index) => [$index['name'] => $index['columns']]
    );

    expect($indexColumns->contains(fn (array $columns) => $columns === ['technician_id', 'start_at', 'end_at']))->toBeTrue()
        ->and($indexColumns->contains(fn (array $columns) => $columns === ['service_bay_id', 'start_at', 'end_at']))->toBeTrue();
});

it('has the required idempotency uniqueness constraint', function () {
    $indexes = Schema::getIndexes('idempotency_keys');

    expect(collect($indexes)->contains(
        fn (array $index) => $index['unique'] === true && $index['columns'] === ['customer_id', 'key']
    ))->toBeTrue();
});
