<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // OpenSSH public key, e.g. "ssh-ed25519 AAAA… comment". Public keys
            // are not secret — stored plaintext, with a computed SHA256
            // fingerprint used to reject duplicates.
            $table->text('public_key');
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->unique(['organization_id', 'fingerprint']);
            $table->index(['organization_id', 'name']);
        });

        // A server can reference a saved key; deleting the key unlinks it but
        // keeps the (denormalized) key text sent to the provider at creation.
        Schema::table('servers', function (Blueprint $table) {
            $table->foreignUuid('ssh_key_id')->nullable()->after('ssh_key')->constrained('ssh_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ssh_key_id');
        });

        Schema::dropIfExists('ssh_keys');
    }
};
