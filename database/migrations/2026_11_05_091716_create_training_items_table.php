<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The training library: short videos, PDF guides and plain articles that
     * office staff publish for dealers and sub-dealers.
     */
    public function up(): void
    {
        Schema::create('training_items', function (Blueprint $table) {
            $table->id();
            $table->string('title_bn', 200);
            $table->string('title_en', 200);
            $table->string('summary', 500)->nullable();
            $table->string('type', 16);
            $table->string('topic', 32);
            $table->string('audience', 16)->default('all');
            $table->string('video_url', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_name', 200)->nullable();
            $table->unsignedInteger('pdf_size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['published', 'topic', 'sort_order']);
        });

        DB::statement("ALTER TABLE training_items ADD CONSTRAINT training_items_type_check CHECK (type IN ('video', 'pdf', 'article'))");
        DB::statement("ALTER TABLE training_items ADD CONSTRAINT training_items_topic_check CHECK (topic IN ('products', 'selling', 'rules', 'portal'))");
        DB::statement("ALTER TABLE training_items ADD CONSTRAINT training_items_audience_check CHECK (audience IN ('all', 'dealers', 'sub_dealers'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('training_items');
    }
};
