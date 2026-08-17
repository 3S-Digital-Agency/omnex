<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CMS campaign pages for the marketing site (offer, promo, comparison).
        // Platform-wide — not tenant-scoped, the same way contact leads are.
        // Content is stored as per-locale section JSON so marketing can ship
        // full EN/FR campaigns without code deploys.
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('type', 30);
            $table->string('status', 20)->default('draft');
            $table->json('content_en');
            $table->json('content_fr');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
