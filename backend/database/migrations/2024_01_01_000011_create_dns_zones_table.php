<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('domain_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('sandbox');
            $table->string('status', 32)->default('active');
            $table->string('external_id')->nullable();
            $table->timestamps();

            $table->unique('domain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zones');
    }
};
