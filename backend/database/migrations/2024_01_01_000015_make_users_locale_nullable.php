<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A null locale means "the user has not chosen a language yet". The SPA
     * detects the browser language in that case and only persists an explicit
     * choice from the account settings.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN locale DROP DEFAULT');
        DB::statement('ALTER TABLE users ALTER COLUMN locale DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET locale = 'en' WHERE locale IS NULL");
        DB::statement("ALTER TABLE users ALTER COLUMN locale SET DEFAULT 'en'");
        DB::statement('ALTER TABLE users ALTER COLUMN locale SET NOT NULL');
    }
};
