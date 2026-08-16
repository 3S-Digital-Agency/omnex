<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('region', 32);
            $table->string('plan', 64);
            $table->string('image', 128);
            $table->string('provider', 32)->default('sandbox');
            $table->string('provider_server_id')->nullable();
            $table->string('status', 32)->default('provisioning');
            $table->string('ipv4', 64)->nullable();
            $table->string('ipv6', 64)->nullable();
            $table->string('ssh_key', 255)->nullable();
            // JSON list of free-form tags, e.g. ["web", "staging"].
            $table->jsonb('tags')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('server_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_operations');
        Schema::dropIfExists('servers');
    }
};
