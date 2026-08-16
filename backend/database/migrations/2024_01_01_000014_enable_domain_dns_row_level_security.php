<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! config('omnex.enforce_rls')) {
            return;
        }

        // Helper functions are created by the 000009 RLS migration.
        foreach (['domains', 'dns_zones', 'dns_records', 'dns_history'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_read ON {$table} FOR SELECT "
                .'USING (organization_id = nexus_current_tenant())'
            );
            DB::statement(
                "CREATE POLICY tenant_write ON {$table} FOR ALL "
                .'USING (organization_id = nexus_current_tenant()) '
                .'WITH CHECK (organization_id = nexus_current_tenant())'
            );
        }
    }

    public function down(): void
    {
        foreach (['domains', 'dns_zones', 'dns_records', 'dns_history'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_read ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_write ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
