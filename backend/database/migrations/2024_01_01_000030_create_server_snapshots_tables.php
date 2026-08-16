<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Automatic snapshot schedule: disabled (default), daily or weekly.
            $table->string('snapshot_frequency', 16)->default('disabled');
            // How long (days) snapshots are kept before the scheduler prunes them.
            $table->unsignedInteger('snapshot_retention_days')->default(7);
            // Last time the scheduler created a snapshot for this server.
            $table->timestamp('last_snapshot_at')->nullable();
        });

        Schema::create('server_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('provider_snapshot_id');
            $table->string('label');
            $table->string('status', 16)->default('available');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['server_id', 'provider_snapshot_id']);
            $table->index(['server_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_snapshots');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['snapshot_frequency', 'snapshot_retention_days', 'last_snapshot_at']);
        });
    }
};
