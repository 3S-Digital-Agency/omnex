<?php

use App\Models\DriveFile;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Site;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    // SET LOCAL ROLE reverts itself at transaction rollback; only the
    // session-scoped GUCs need an explicit reset.
    DB::statement("SELECT set_config('nexus.tenant_id', '', false)");
    DB::statement("SELECT set_config('nexus.user_id', '', false)");
});

/**
 * Applies the RLS migration against the test schema. RefreshDatabase runs the
 * migrations with OMNEX_ENFORCE_RLS=false (so no policies are created), which
 * is exactly why we enable the flag and invoke the migration here: it lets us
 * prove the policies behave correctly before anyone flips the flag in prod.
 */
function enableRls(): void
{
    config(['omnex.enforce_rls' => true]);
    $migration = require base_path('database/migrations/2024_01_01_000046_extend_tenant_row_level_security.php');
    $migration->up();
}

/**
 * PostgreSQL bypasses RLS for superusers and (without FORCE) for table owners.
 * The default app role (`omnex`) is the migration owner and a superuser, so the
 * test switches to a least-privilege role with SET LOCAL ROLE — scoped to the
 * same RefreshDatabase transaction, which also makes the just-created role and
 * grants visible. This is exactly the role split production needs before
 * enabling OMNEX_ENFORCE_RLS.
 */
function switchToAppRole(): void
{
    DB::statement(
        'DO $$ BEGIN '
        ."IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'omnex_app') THEN "
        .'CREATE ROLE omnex_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS; '
        .'END IF; END $$'
    );
    DB::statement('GRANT USAGE ON SCHEMA public TO omnex_app');
    DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO omnex_app');
    DB::statement('SET LOCAL ROLE omnex_app');
}

it('scopes Drive, Sites, Billing and Cloud to the active tenant under RLS', function () {
    enableRls();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Drive (Phase 4)
    DriveFile::factory()->create(['organization_id' => $orgA->id]);
    DriveFile::factory()->create(['organization_id' => $orgB->id]);
    // Sites (Phase 5)
    Site::factory()->create(['organization_id' => $orgA->id]);
    Site::factory()->create(['organization_id' => $orgB->id]);
    // Cloud (Phase 8)
    Server::factory()->create(['organization_id' => $orgA->id]);
    Server::factory()->create(['organization_id' => $orgB->id]);
    // Billing (Phase 6)
    $plan = Plan::factory()->create();
    Subscription::create(['organization_id' => $orgA->id, 'plan_id' => $plan->id, 'provider_subscription_id' => 'sub-a', 'status' => 'active']);
    Subscription::create(['organization_id' => $orgB->id, 'plan_id' => $plan->id, 'provider_subscription_id' => 'sub-b', 'status' => 'active']);

    switchToAppRole();

    // System context (no tenant GUC) sees every row — the escape hatch that
    // mirrors the application-level withoutTenancy() used by jobs/webhooks.
    expect(DB::table('drive_files')->count())->toBe(2)
        ->and(DB::table('sites')->count())->toBe(2)
        ->and(DB::table('servers')->count())->toBe(2)
        ->and(DB::table('subscriptions')->count())->toBe(2);

    // A tenant context sees only its own rows across every module.
    DB::statement('SELECT set_config(?, ?, false)', ['nexus.tenant_id', $orgA->id]);

    expect(DB::table('drive_files')->count())->toBe(1)
        ->and(DB::table('drive_files')->value('organization_id'))->toBe($orgA->id)
        ->and(DB::table('sites')->count())->toBe(1)
        ->and(DB::table('servers')->count())->toBe(1)
        ->and(DB::table('subscriptions')->count())->toBe(1);
});

it('blocks writing another tenant\'s rows under RLS', function () {
    enableRls();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    switchToAppRole();
    DB::statement('SELECT set_config(?, ?, false)', ['nexus.tenant_id', $orgA->id]);

    // The WITH CHECK policy must reject an INSERT targeting another tenant.
    // The inner transaction (a savepoint under RefreshDatabase) keeps the outer
    // transaction usable after the expected rejection.
    expect(fn () => DB::transaction(fn () => DB::table('drive_files')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgB->id,
        'name' => 'b.txt',
        'storage_key' => 'org/b/v1',
    ])))->toThrow(QueryException::class);
});
