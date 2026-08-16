<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('framework', 32)->default('static');
            $table->string('git_url');
            $table->string('git_branch', 255)->default('main');
            $table->string('provider', 32)->default('sandbox');
            $table->string('provider_site_id')->nullable();
            $table->string('status', 32)->default('provisioning');
            $table->uuid('current_deployment_id')->nullable();
            $table->string('url')->nullable();
            // Encrypted at the application layer (encrypted:array cast); the
            // stored value is a ciphertext string, so the column is text.
            $table->text('environment_variables')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('site_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('commit_sha', 64)->nullable();
            $table->string('status', 32)->default('building');
            $table->string('url')->nullable();
            $table->text('logs')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        // Self-referencing FK to the current live deployment is added after the
        // table exists so PostgreSQL can resolve the referenced constraint.
        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('current_deployment_id')->references('id')->on('site_deployments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_deployments');
        Schema::dropIfExists('sites');
    }
};
