<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            // Full certificate chain (public information). Persisted so the
            // provider can revoke/renew and report status without a second
            // ACME call. The private key is NOT stored here (see SslService).
            $table->text('certificate_pem')->nullable()->after('auto_renew');
        });
    }

    public function down(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->dropColumn('certificate_pem');
        });
    }
};
