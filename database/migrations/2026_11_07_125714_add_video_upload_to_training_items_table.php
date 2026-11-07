<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A training video can be an uploaded file (mp4 or webm) instead of a YouTube or Facebook link.
     */
    public function up(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->string('video_path')->nullable()->after('video_url');
            $table->string('video_name', 200)->nullable()->after('video_path');
            $table->unsignedBigInteger('video_size')->nullable()->after('video_name');
            $table->string('video_mime', 40)->nullable()->after('video_size');
        });
    }

    public function down(): void
    {
        Schema::table('training_items', function (Blueprint $table) {
            $table->dropColumn(['video_path', 'video_name', 'video_size', 'video_mime']);
        });
    }
};
