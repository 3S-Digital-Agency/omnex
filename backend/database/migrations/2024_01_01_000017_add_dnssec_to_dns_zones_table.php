<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->boolean('dnssec_enabled')->default(false)->after('status');
            $table->string('dnssec_status', 32)->default('unsigned')->after('dnssec_enabled');
            $table->jsonb('dnssec_ds_records')->nullable()->after('dnssec_status');
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropColumn(['dnssec_enabled', 'dnssec_status', 'dnssec_ds_records']);
        });
    }
};
