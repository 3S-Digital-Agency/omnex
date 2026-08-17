<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persisted security-score samples. Every meaningful scan (a manual
        // scan, a dismiss/reopen, or a score change) records one sample, so
        // the Security cockpit can render the score evolution over time and
        // the scan history without re-running checks.
        Schema::create('security_score_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->unsignedInteger('open');
            $table->unsignedInteger('high');
            $table->unsignedInteger('medium');
            $table->unsignedInteger('low');
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_score_samples');
    }
};
