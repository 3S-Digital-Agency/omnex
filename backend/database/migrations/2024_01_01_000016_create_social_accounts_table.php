<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per external OAuth identity (Google, Microsoft, Apple, Facebook,
     * Amazon, sandbox). A user can own several — this is what makes "login with
     * any provider" interoperable instead of a per-provider silo. The table is
     * intentionally NOT tenant-scoped: identity exists before any organization
     * context, exactly like the users table.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->string('provider_email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();
            // Stored encrypted (cast 'encrypted' on the model) → text, not jsonb.
            $table->text('token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
            $table->index('provider_email');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
