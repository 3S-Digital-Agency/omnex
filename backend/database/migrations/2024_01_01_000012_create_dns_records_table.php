<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name');
            $table->text('content');
            $table->integer('ttl')->default(3600);
            $table->integer('priority')->nullable();
            $table->boolean('proxied')->default(false);
            $table->string('external_id')->nullable();
            $table->timestamps();

            $table->index(['zone_id', 'type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
