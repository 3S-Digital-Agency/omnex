<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('status', 32)->default('pending');
            $table->string('provider', 32)->default('sandbox');
            $table->string('external_id')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expiration_notified_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('privacy_protection')->default(true);
            $table->boolean('transfer_lock')->default(true);
            $table->jsonb('nameservers')->nullable();
            $table->jsonb('contacts')->nullable();
            $table->string('auth_code')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
