<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('rule');
            $table->string('dedupe_key');
            $table->string('severity', 16);
            $table->string('status', 16)->default('open');
            $table->string('resource_type')->nullable();
            $table->uuid('resource_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'dedupe_key']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_findings');
    }
};
