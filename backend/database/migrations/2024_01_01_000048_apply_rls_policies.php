<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Applies the tenant RLS policies to deployments where migration 000046
     * already ran as a no-op: 000046 used to early-return when
     * OMNEX_ENFORCE_RLS was off (the default), yet Laravel still recorded it
     * as run. 000046 is now unconditional, but a recorded migration is never
     * re-run, so this bridge re-applies the same idempotent policy definitions
     * (`DROP POLICY IF EXISTS` + `CREATE POLICY`) for those environments.
     */
    public function up(): void
    {
        $extend = require __DIR__.'/2024_01_01_000046_extend_tenant_row_level_security.php';
        $extend->up();
    }

    public function down(): void
    {
        // Intentionally a no-op: rolling this migration back must not disable
        // Row-Level Security on tables that are (or are about to be) protected.
        // The policies are idempotent and stay active.
    }
};
