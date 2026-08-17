<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authenticators', function (Blueprint $table) {
            // Full WebAuthn CredentialRecord (credential public key in COSE/CBOR,
            // aaguid, attestation type, trust path, transports, user handle, counter)
            // serialized with web-auth/webauthn-lib's CredentialRecord denormalizer.
            $table->jsonb('credential_data')->nullable()->after('public_key');
        });
    }

    public function down(): void
    {
        Schema::table('authenticators', function (Blueprint $table) {
            $table->dropColumn('credential_data');
        });
    }
};
