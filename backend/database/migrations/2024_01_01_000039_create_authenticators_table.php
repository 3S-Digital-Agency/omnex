<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authenticators', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 512);
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name')->default('Security key');
            $table->string('transport')->nullable(); // usb / nfc / ble / internal
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'credential_id']);
            $table->index('credential_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authenticators');
    }
};
