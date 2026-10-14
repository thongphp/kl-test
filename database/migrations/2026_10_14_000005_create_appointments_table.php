<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('dealership_id')->constrained('dealerships')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('service_type_id')->constrained('service_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('technician_id')->constrained('technicians')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('service_bay_id')->constrained('service_bays')->cascadeOnUpdate()->restrictOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status')->default('confirmed');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['technician_id', 'start_at', 'end_at']);
            $table->index(['service_bay_id', 'start_at', 'end_at']);
            $table->index(['customer_id', 'start_at']);
            $table->index(['vehicle_id', 'start_at']);
            $table->index(['dealership_id', 'start_at']);
            $table->index(['service_type_id', 'start_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
