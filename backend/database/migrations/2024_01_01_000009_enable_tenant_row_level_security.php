<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Session-scoped helpers used by the policies. They read GUCs set by
        // ResolveTenant middleware (nexus.tenant_id / nexus.user_id).
        DB::statement(
            "CREATE OR REPLACE FUNCTION nexus_current_tenant() RETURNS uuid LANGUAGE sql STABLE AS $$ "
            ."SELECT NULLIF(current_setting('nexus.tenant_id', true), '')::uuid $$"
        );
        DB::statement(
            "CREATE OR REPLACE FUNCTION nexus_current_user() RETURNS uuid LANGUAGE sql STABLE AS $$ "
            ."SELECT NULLIF(current_setting('nexus.user_id', true), '')::uuid $$"
        );

        // Defense-in-depth stays off until the RLS test suite passes on a real
        // PostgreSQL instance (see config/nexus.php).
        if (! config('nexus.enforce_rls')) {
            return;
        }

        foreach (['memberships', 'invitations', 'audit_logs'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_read ON {$table} FOR SELECT "
                ."USING (organization_id = nexus_current_tenant())"
            );
            DB::statement(
                "CREATE POLICY tenant_write ON {$table} FOR ALL "
                ."USING (organization_id = nexus_current_tenant()) "
                ."WITH CHECK (organization_id = nexus_current_tenant())"
            );
        }

        DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        DB::statement(
            'CREATE POLICY notification_read ON notifications FOR SELECT '
            .'USING (user_id = nexus_current_user())'
        );
        DB::statement(
            'CREATE POLICY notification_write ON notifications FOR ALL '
            .'USING (user_id = nexus_current_user()) WITH CHECK (user_id = nexus_current_user())'
        );
    }

    public function down(): void
    {
        foreach (['memberships', 'invitations', 'audit_logs'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_read ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_write ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP POLICY IF EXISTS notification_read ON notifications');
        DB::statement('DROP POLICY IF EXISTS notification_write ON notifications');
        DB::statement('ALTER TABLE notifications DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP FUNCTION IF EXISTS nexus_current_tenant()');
        DB::statement('DROP FUNCTION IF EXISTS nexus_current_user()');
    }
};
