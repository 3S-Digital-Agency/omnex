<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_propagation_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->string('nameserver', 253);
            $table->string('record_type', 16);
            $table->string('record_name');
            $table->jsonb('expected')->nullable();
            $table->jsonb('observed')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();

            $table->index(['zone_id', 'nameserver']);
            $table->index(['zone_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_propagation_checks');
    }
};
