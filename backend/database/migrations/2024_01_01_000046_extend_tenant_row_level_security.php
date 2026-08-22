<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tenant-scoped tables (organization_id = nexus_current_tenant()).
     *
     * Supersedes the partial policies from 000009/000014 by (re)creating them
     * uniformly with the system-context escape hatch. `notifications` is
     * user-scoped and is handled separately at the bottom.
     *
     * The escape hatch matters: the application deliberately reads across
     * tenants via `withoutTenancy()` in jobs, webhooks and cron (e.g.
     * BillingService, CheckDomainExpirations, NotifyExpiringDomain). Those
     * code paths never set the tenant GUC, so a bare
     * `organization_id = nexus_current_tenant()` policy would return zero rows
     * and silently break billing/expiry/notifications. `nexus_current_tenant()
     * IS NULL` therefore means "system context" and mirrors the application's
     * explicit `withoutTenancy()` semantics — the DB layer still hard-scopes
     * every request that *does* carry a tenant context.
     *
     * FORCE ROW LEVEL SECURITY is required: the application connects as the
     * table owner (`omnex`), and PostgreSQL exempts the owner from RLS unless
     * FORCE is set. Without it the policies below would silently never apply.
     */
    private const TENANT_TABLES = [
        // IAM / audit (covered by 000009 — normalized here for the escape).
        'memberships',
        'invitations',
        'audit_logs',
        // Domains / DNS (covered by 000014 — normalized here for the escape).
        'domains',
        'dns_zones',
        'dns_records',
        'dns_history',
        'dns_propagation_checks',
        // Drive (Phase 4).
        'drive_folders',
        'drive_files',
        'drive_versions',
        // Security Center (Phase 7).
        'security_findings',
        'security_score_samples',
        // Sites (Phase 5).
        'sites',
        'site_deployments',
        // Billing (Phase 6) — `plans`/`coupons` are global catalog rows.
        'subscriptions',
        'invoices',
        'org_credit_entries',
        'coupon_redemptions',
        // Cloud (Phase 8).
        'servers',
        'server_operations',
        'server_snapshots',
        'server_metric_samples',
        'ssh_keys',
        // SSL (Phase 3 extension).
        'ssl_certificates',
        'ssl_checks',
    ];

    public function up(): void
    {
        // Unconditional: the policies carry a `nexus_current_tenant() IS NULL`
        // escape hatch, so they are safe even while OMNEX_ENFORCE_RLS is off
        // (no tenant GUC set => every row is visible; ResolveTenant only sets
        // the GUC when the flag is on). Gating this on the flag was a
        // chicken-and-egg: Laravel records the migration as run even when it
        // early-returns, so flipping the flag later never created the policies.
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_read ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_write ON {$table}");

            DB::statement(
                "CREATE POLICY tenant_read ON {$table} FOR SELECT "
                .'USING (nexus_current_tenant() IS NULL OR organization_id = nexus_current_tenant())'
            );
            DB::statement(
                "CREATE POLICY tenant_write ON {$table} FOR ALL "
                .'USING (nexus_current_tenant() IS NULL OR organization_id = nexus_current_tenant()) '
                .'WITH CHECK (nexus_current_tenant() IS NULL OR organization_id = nexus_current_tenant())'
            );
        }

        // notifications is user-scoped (user_id), not organization-scoped.
        DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notifications FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS notification_read ON notifications');
        DB::statement('DROP POLICY IF EXISTS notification_write ON notifications');
        DB::statement(
            'CREATE POLICY notification_read ON notifications FOR SELECT '
            .'USING (nexus_current_user() IS NULL OR user_id = nexus_current_user())'
        );
        DB::statement(
            'CREATE POLICY notification_write ON notifications FOR ALL '
            .'USING (nexus_current_user() IS NULL OR user_id = nexus_current_user()) '
            .'WITH CHECK (nexus_current_user() IS NULL OR user_id = nexus_current_user())'
        );
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_read ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_write ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP POLICY IF EXISTS notification_read ON notifications');
        DB::statement('DROP POLICY IF EXISTS notification_write ON notifications');
        DB::statement('ALTER TABLE notifications NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notifications DISABLE ROW LEVEL SECURITY');
    }
};
