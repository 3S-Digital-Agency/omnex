<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The least-privilege runtime role. The application connects with this role
     * in production so that PostgreSQL Row-Level Security actually applies
     * (superusers and owners bypass RLS). `omnex` stays the owner and runs only
     * migrations, via `php artisan migrate --database=pgsql_migrate`.
     */
    private const ROLE = 'omnex_app';

    public function up(): void
    {
        // Password is read here (before `config:cache`) via env(); it falls back
        // to DB_PASSWORD so local/CI (trust auth / shared password) keep working.
        $password = (string) env('DB_APP_PASSWORD', env('DB_PASSWORD', ''));

        DB::statement(
            'DO $$ BEGIN '
            ."IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '".self::ROLE."') THEN "
            .'CREATE ROLE '.self::ROLE.' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS; '
            .'END IF; END $$'
        );

        if ($password !== '') {
            DB::statement('ALTER ROLE '.self::ROLE.' WITH LOGIN PASSWORD '.DB::connection()->getPdo()->quote($password));
        }

        // Data-plane access on every existing object…
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::ROLE);
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.self::ROLE);
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.self::ROLE);

        // …and on every object the owner creates from now on.
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.self::ROLE);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO '.self::ROLE);
    }

    public function down(): void
    {
        // The role itself and its direct grants are left in place: dropping a
        // role the runtime still uses would take production down. Only the
        // forward-looking default privileges are reverted.
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM '.self::ROLE);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM '.self::ROLE);
    }
};
