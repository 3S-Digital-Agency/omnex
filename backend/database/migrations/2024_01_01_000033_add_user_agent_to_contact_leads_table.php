<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anti-spam forensics: the visitor's browser user agent alongside the
        // IP address already recorded when the lead was created.
        Schema::table('contact_leads', function (Blueprint $table) {
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('contact_leads', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
