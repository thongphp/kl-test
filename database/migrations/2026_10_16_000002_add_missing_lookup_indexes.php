<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 index hardening. PostgreSQL does not index foreign keys automatically.
 * Idempotent: only adds an index when no index on exactly those columns exists.
 */
return new class extends Migration
{
    private const INDEXES = [
        'appointments' => [['customer_id', 'start_at'], ['vehicle_id'], ['dealership_id'], ['service_type_id']],
        'technicians' => [['dealership_id']],
        'service_bays' => [['dealership_id']],
        'vehicles' => [['customer_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $columns) {
                if (! Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: cannot tell which indexes this migration created
        // versus ones that already existed. Re-running up() is idempotent.
    }
};
