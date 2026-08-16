<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->foreignUuid('record_id')->nullable()->constrained('dns_records')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['zone_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_history');
    }
};
