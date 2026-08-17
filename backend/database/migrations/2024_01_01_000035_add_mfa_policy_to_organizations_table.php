<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MFA enforcement policy: 'optional' (recommended, default) or
        // 'required' (enforced — the security scan escalates and surfaces a
        // policy finding until every active member enables MFA).
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('mfa_policy', 20)->default('optional')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('mfa_policy');
        });
    }
};
