<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->string('tracking_number', 24)->nullable()->unique();
            $table->string('product_name', 150)->nullable();
            $table->string('batch_number', 60)->nullable();
        });

        Schema::create('quality_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'created_at']);
        });

        Schema::create('health_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->text('body');
            $table->string('image_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_articles');
        Schema::dropIfExists('quality_documents');

        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropColumn(['tracking_number', 'product_name', 'batch_number']);
        });
    }
};
