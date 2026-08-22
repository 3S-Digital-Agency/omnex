<?php

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The role split is what makes Row-Level Security actually effective: the app
 * must not connect as a superuser/owner. This test proves the `omnex_app` role
 * provisioned by migration 000047 is least-privilege — it can read/write rows
 * (DML) but cannot create objects (DDL) and has no role/cluster privileges.
 */
it('provisions omnex_app as a non-superuser runtime role with DML but no DDL', function () {
    $role = DB::selectOne(
        "SELECT rolsuper::text AS super, rolcreatedb::text AS createdb, "
        ."rolcreaterole::text AS createrole, rolbypassrls::text AS bypassrls "
        ."FROM pg_roles WHERE rolname = 'omnex_app'"
    );

    expect($role)->not->toBeNull();
    expect($role->super)->toBe('false');
    expect($role->createdb)->toBe('false');
    expect($role->createrole)->toBe('false');
    expect($role->bypassrls)->toBe('false');

    // The role has data-plane grants: it can read a table it does not own.
    Organization::factory()->create();
    DB::statement('SET LOCAL ROLE omnex_app');
    expect(DB::table('organizations')->count())->toBe(1);

    // Least privilege: it cannot create objects in the public schema.
    // The inner transaction is a savepoint, keeping the test's outer
    // transaction usable after the expected rejection.
    expect(fn () => DB::transaction(fn () => DB::statement('CREATE TABLE __probe (id integer)')))
        ->toThrow(QueryException::class);
});
