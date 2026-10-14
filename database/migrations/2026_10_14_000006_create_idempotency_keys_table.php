<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key');
            $table->string('request_fingerprint');
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['customer_id', 'key']);
            $table->index('expires_at');
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
