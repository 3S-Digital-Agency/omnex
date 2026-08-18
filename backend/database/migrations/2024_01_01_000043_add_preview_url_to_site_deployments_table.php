<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_deployments', function (Blueprint $table) {
            $table->string('preview_url')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('site_deployments', function (Blueprint $table) {
            $table->dropColumn('preview_url');
        });
    }
};
