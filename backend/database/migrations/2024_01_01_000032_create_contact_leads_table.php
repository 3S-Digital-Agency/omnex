<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public marketing-site leads. Not tenant-scoped: a visitor submits
        // without an account; the owning team is notified instead.
        Schema::create('contact_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 120)->nullable();
            $table->string('subject', 190);
            $table->text('message');
            $table->string('source', 60)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_leads');
    }
};
