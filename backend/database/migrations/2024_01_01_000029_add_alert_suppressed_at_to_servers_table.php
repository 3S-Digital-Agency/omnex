<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Per-metric ISO timestamps of the last threshold alert that was
            // fired, e.g. {"cpu": "2026-08-16T18:00:00+00:00"}. Used to enforce
            // the alert cooldown without a separate table.
            $table->jsonb('alert_suppressed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('alert_suppressed_at');
        });
    }
};
