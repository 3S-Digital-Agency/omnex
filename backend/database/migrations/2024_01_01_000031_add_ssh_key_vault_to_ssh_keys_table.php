<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Encrypted vault for private keys. Only ciphertext + KDF salt +
        // verifier are stored — never the plaintext private key. A vault
        // password is required to decrypt (SshKeyService::unlock), and a
        // wrong password is rejected by the verifier before any decryption.
        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->text('encrypted_private_key')->nullable()->after('public_key');
            $table->string('private_key_salt', 64)->nullable()->after('encrypted_private_key');
            $table->string('private_key_verifier', 64)->nullable()->after('private_key_salt');
            $table->timestamp('vault_enabled_at')->nullable()->after('private_key_verifier');
        });
    }

    public function down(): void
    {
        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->dropColumn(['encrypted_private_key', 'private_key_salt', 'private_key_verifier', 'vault_enabled_at']);
        });
    }
};
