<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->index(['organization_id', 'parent_id']);
        });

        // Self-referencing FK is added after the table exists so PostgreSQL
        // can resolve the referenced unique constraint on `id`.
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('drive_folders')->nullOnDelete();
        });

        Schema::create('drive_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('folder_id')->nullable()->constrained('drive_folders')->nullOnDelete();
            $table->string('name');
            $table->string('storage_key');
            $table->string('mime_type')->default('application/octet-stream');
            $table->bigInteger('size')->default(0);
            $table->string('checksum')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('active');
            $table->timestamp('trashed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'folder_id']);
        });

        Schema::create('drive_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('file_id')->constrained('drive_files')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_key');
            $table->bigInteger('size')->default(0);
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->index(['file_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_versions');
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
    }
};
