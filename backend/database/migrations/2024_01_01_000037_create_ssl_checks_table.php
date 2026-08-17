<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Certificate monitoring pipeline: the security scan checks managed
        // targets (domains, sites) through SslCheckerInterface, persists the
        // result here and derives findings (ssl_missing / ssl_expiring /
        // ssl_invalid) from it.
        Schema::create('ssl_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20); // site | domain
            $table->uuid('target_id');
            $table->string('status', 20); // valid | expiring | invalid
            $table->integer('days_remaining')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'target_type', 'target_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_checks');
    }
};
