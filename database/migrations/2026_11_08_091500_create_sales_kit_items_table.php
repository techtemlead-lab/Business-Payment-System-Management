<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approved sales materials the owner uploads for partners to view and download.
     */
    public function up(): void
    {
        Schema::create('sales_kit_items', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->string('kind', 20);
            $table->string('language', 2);
            $table->text('caption')->nullable();
            $table->string('file_path', 255);
            $table->string('file_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->index(['kind', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_kit_items');
    }
};
