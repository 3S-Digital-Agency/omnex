<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metric_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->unsignedInteger('cpu');
            // Bytes (unsigned 64-bit).
            $table->unsignedBigInteger('memory_used');
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('disk_used');
            $table->unsignedBigInteger('disk_total');
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->index(['server_id', 'sampled_at']);
            $table->index(['organization_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metric_samples');
    }
};
