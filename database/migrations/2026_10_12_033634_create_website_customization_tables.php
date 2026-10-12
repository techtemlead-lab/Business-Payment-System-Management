<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('website_settings', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->json('draft');
            $table->json('published');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('published_revision')->default(1);
            $table->json('scheduled_document')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();
        });
        Schema::create('website_versions', function (Blueprint $table): void {
            $table->id();
            $table->json('document');
            $table->string('summary', 250);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('website_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key')->unique();
            $table->string('path');
            $table->string('name', 160);
            $table->string('alt', 250)->default('');
            $table->string('folder', 80)->default('General');
            $table->string('mime');
            $table->unsignedInteger('size');
            $table->timestamp('created_at');
        });
        Schema::table('dealership_requests', function (Blueprint $table): void {
            $table->json('additional_answers')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dealership_requests', function (Blueprint $table): void {
            $table->dropColumn('additional_answers');
        });
        Schema::dropIfExists('website_media');
        Schema::dropIfExists('website_versions');
        Schema::dropIfExists('website_settings');
    }
};
